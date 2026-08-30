<?php

namespace App\Http\Controllers\Api;

use App\Enums\LeadAttachmentStatus;
use App\Enums\LeadSourceType;
use App\Enums\LeadStatus;
use App\Enums\TenantStatus;
use App\Events\LeadReceived;
use App\Events\LeadUpdated;
use App\Http\Controllers\Controller;
use App\Jobs\DownloadLeadAttachment;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class LeadWebhookController extends Controller
{
    /**
     * Verify a Facebook Page webhook subscription challenge — shared by
     * Messenger and Lead Ads, so this accepts whichever of the two is
     * configured for the tenant (a tenant may only have one set up).
     */
    public function verifyFacebook(Request $request, string $tenant_code): Response
    {
        $source = $this->resolveSourceOrNull($tenant_code, LeadSourceType::FacebookMessenger)
            ?? $this->resolveSourceOrNull($tenant_code, LeadSourceType::FacebookLeadAds);

        abort_if($source === null, 404);

        return $this->verifyHubChallenge($request, $source);
    }

    /**
     * Handle an incoming Facebook Page webhook — Messenger conversations
     * (`entry[].messaging[]`) and Lead Ads submissions (`entry[].changes[]`
     * with `field === 'leadgen'`) share this one URL, because that's how
     * Meta delivers both: a single Page-level webhook subscription, not
     * separate endpoints per capability.
     *
     * The Messenger source is resolved lazily, only once an actual
     * `messaging` event needs it — a tenant may configure Lead Ads without
     * ever setting up Messenger, and this must not 404 for them just
     * because no Messenger source exists.
     */
    public function facebookMessenger(Request $request, string $tenant_code): JsonResponse
    {
        $source = null;

        foreach ($request->array('entry') as $entry) {
            foreach (($entry['messaging'] ?? []) as $messaging) {
                $source ??= $this->resolveSource($tenant_code, LeadSourceType::FacebookMessenger);

                $senderId = (string) ($messaging['sender']['id'] ?? 'unknown');
                $messageText = $messaging['message']['text'] ?? null;
                $attachments = $this->extractMessagingAttachments($messaging);
                $sentAt = $this->messagingTimestamp($messaging);

                $existingLead = Lead::query()
                    ->where('source_id', $source->id)
                    ->where('source_data->facebook_user_id', $senderId)
                    ->first();

                if ($existingLead instanceof Lead) {
                    $this->appendFacebookMessage($existingLead, $messageText, $messaging, $attachments, $senderId, $source, $sentAt);

                    $source->update(['last_synced_at' => now()]);

                    continue;
                }

                $profile = $this->fetchFacebookProfile($source, $senderId);

                $lead = $this->createLead($source, [
                    'name' => $profile['name'] ?? "Facebook User {$senderId}",
                    'message' => $messageText,
                    'source_data' => [
                        'facebook_user_id' => $senderId,
                        'facebook_profile_pic' => $profile['profile_pic'],
                        'messages' => [$messaging],
                    ],
                ]);

                $this->logIfMultipleAttachments($attachments, $lead);

                foreach ($attachments as $attachment) {
                    $this->createPendingAttachmentAndDispatch($lead, LeadSourceType::FacebookMessenger, $attachment, $senderId, $source, $sentAt);
                }
            }

            foreach (($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? null) === 'leadgen') {
                    $this->handleFacebookLeadAd($tenant_code, $change['value'] ?? []);
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Verify an Instagram Messaging webhook subscription challenge.
     */
    public function verifyInstagram(Request $request, string $tenant_code): Response
    {
        return $this->verifyHubChallenge($request, $this->resolveSource($tenant_code, LeadSourceType::Instagram));
    }

    /**
     * Handle an incoming Instagram Direct Message webhook. Instagram
     * Messaging (via a linked Facebook Page) delivers the same
     * entry[].messaging[] shape as Messenger.
     */
    public function instagram(Request $request, string $tenant_code): JsonResponse
    {
        $source = $this->resolveSource($tenant_code, LeadSourceType::Instagram);

        foreach ($request->array('entry') as $entry) {
            foreach (($entry['messaging'] ?? []) as $messaging) {
                $senderId = (string) ($messaging['sender']['id'] ?? 'unknown');
                $messageText = $messaging['message']['text'] ?? null;
                $attachments = $this->extractMessagingAttachments($messaging);
                $sentAt = $this->messagingTimestamp($messaging);

                $existingLead = Lead::query()
                    ->where('source_id', $source->id)
                    ->where('source_data->instagram_user_id', $senderId)
                    ->first();

                if ($existingLead instanceof Lead) {
                    $this->appendInstagramMessage($existingLead, $messageText, $messaging, $attachments, $senderId, $source, $sentAt);

                    $source->update(['last_synced_at' => now()]);

                    continue;
                }

                $profile = $this->fetchInstagramProfile($source, $senderId);

                $lead = $this->createLead($source, [
                    'name' => $profile['name'] ?? "Instagram User {$senderId}",
                    'message' => $messageText,
                    'source_data' => [
                        'instagram_user_id' => $senderId,
                        'instagram_profile_pic' => $profile['profile_pic'],
                        'messages' => [$messaging],
                    ],
                ]);

                $this->logIfMultipleAttachments($attachments, $lead);

                foreach ($attachments as $attachment) {
                    $this->createPendingAttachmentAndDispatch($lead, LeadSourceType::Instagram, $attachment, $senderId, $source, $sentAt);
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Verify a WhatsApp Cloud API webhook subscription challenge.
     */
    public function verifyWhatsapp(Request $request, string $tenant_code): Response
    {
        return $this->verifyHubChallenge($request, $this->resolveSource($tenant_code, LeadSourceType::Whatsapp));
    }

    /**
     * Handle an incoming WhatsApp Cloud API message webhook.
     *
     * Always acknowledges with 200 quickly, even when tenant/source
     * resolution or message processing fails — Meta disables a webhook
     * subscription after enough non-200 responses or timeouts, so failures
     * here are logged for our own visibility rather than surfaced to Meta.
     */
    public function whatsapp(Request $request, string $tenant_code): JsonResponse
    {
        // Pre-encoded to a flat string rather than passed as nested context
        // arrays: Monolog's context normalizer aborts past 9 levels of
        // nesting, and a real WhatsApp payload (entry[].changes[].value
        // .messages[].text.body, plus the 'all'/'headers' wrapper keys) goes
        // past that — the very fields we need to see get silently replaced
        // with "Over 9 levels deep, aborting normalization".
        Log::channel('whatsapp')->info('WA INCOMING '.json_encode([
            'all' => $request->all(),
            'headers' => $request->headers->all(),
        ]));

        try {
            $source = $this->resolveSource($tenant_code, LeadSourceType::Whatsapp);

            foreach ($request->array('entry') as $entry) {
                foreach (($entry['changes'] ?? []) as $change) {
                    foreach (($change['value']['messages'] ?? []) as $message) {
                        $attachment = $this->extractWhatsappAttachment($message);
                        $senderId = (string) ($message['from'] ?? 'unknown');
                        $senderPhone = $message['from'] ?? null;
                        $messageText = $message['text']['body'] ?? $attachment['caption'] ?? null;
                        $sentAt = isset($message['timestamp'])
                            ? Carbon::createFromTimestamp((int) $message['timestamp'])
                            : now();

                        $existingLead = is_string($senderPhone) && $senderPhone !== ''
                            ? Lead::query()->where('source_id', $source->id)->where('phone', $senderPhone)->first()
                            : null;

                        if ($existingLead instanceof Lead) {
                            $this->appendWhatsappMessage($existingLead, $messageText, $message, $attachment, $senderId, $source, $sentAt);

                            $source->update(['last_synced_at' => now()]);

                            continue;
                        }

                        $contactName = $this->whatsappContactName($change, $senderPhone);

                        $lead = $this->createLead($source, [
                            'name' => $contactName ?? ('WhatsApp User '.$senderId),
                            'phone' => $senderPhone,
                            'message' => $messageText,
                            'source_data' => ['messages' => [$message]],
                        ]);

                        if ($attachment !== null) {
                            $this->createPendingAttachmentAndDispatch($lead, LeadSourceType::Whatsapp, $attachment, $senderId, $source, $sentAt);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            Log::channel('whatsapp')->error('WA PROCESSING FAILED', [
                'tenant_code' => $tenant_code,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle an incoming website form submission, authenticated via the source's API key.
     */
    public function websiteForm(Request $request, string $tenant_code): JsonResponse
    {
        $source = $this->resolveSource($tenant_code, LeadSourceType::WebsiteForm);

        $apiKey = $request->header('X-Api-Key', '');

        abort_unless(
            $apiKey !== '' && hash_equals((string) ($source->config['api_key'] ?? ''), $apiKey),
            401
        );

        $this->createLead($source, [
            'name' => $request->input('name', 'Website Visitor'),
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            'message' => $request->input('message'),
            'source_data' => $request->except(['name', 'email', 'phone', 'message']),
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Resolve the active lead source of the given type for a tenant, by
     * tenant code. Rejects if the tenant itself is not active.
     */
    private function resolveSource(string $tenantCode, LeadSourceType $type): LeadSource
    {
        $tenant = Tenant::where('tenant_code', $tenantCode)
            ->where('status', TenantStatus::Active)
            ->firstOrFail();

        return LeadSource::query()
            ->where('tenant_id', $tenant->id)
            ->where('type', $type)
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function resolveSourceOrNull(string $tenantCode, LeadSourceType $type): ?LeadSource
    {
        try {
            return $this->resolveSource($tenantCode, $type);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Respond to a Meta `hub.mode=subscribe` verification handshake.
     */
    private function verifyHubChallenge(Request $request, LeadSource $source): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = (string) $request->query('hub_challenge', '');

        abort_unless(
            $mode === 'subscribe' && $token === ($source->config['verify_token'] ?? null),
            403
        );

        return response($challenge, 200);
    }

    /**
     * Create a new lead from webhook data and fire the LeadReceived event.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createLead(LeadSource $source, array $attributes): Lead
    {
        $lead = Lead::create(array_merge([
            'tenant_id' => $source->tenant_id,
            'source_id' => $source->id,
            'status' => LeadStatus::New,
        ], $attributes));

        $source->update(['last_synced_at' => now()]);

        event(new LeadReceived($lead));

        return $lead;
    }

    /**
     * Append a follow-up Messenger message from the same Facebook user onto
     * their existing lead, instead of creating a duplicate lead per message.
     *
     * @param  mixed  $messaging
     * @param  array<int, array{type: string, url: string}>  $attachments
     */
    private function appendFacebookMessage(Lead $lead, ?string $messageText, $messaging, array $attachments, string $senderId, LeadSource $source, CarbonInterface $sentAt): void
    {
        $sourceData = $lead->source_data ?? [];

        if ($lead->name === "Facebook User {$senderId}") {
            $profile = $this->fetchFacebookProfile($source, $senderId);

            if ($profile['name'] !== null) {
                $lead->name = $profile['name'];
                $sourceData['facebook_profile_pic'] = $profile['profile_pic'];
            }
        }

        if ($messageText !== null && $messageText !== '') {
            $lead->message = trim(($lead->message ?? '')."\n".$messageText);
        }

        $sourceData['messages'][] = $messaging;
        $lead->source_data = $sourceData;

        $lead->save();

        $this->logIfMultipleAttachments($attachments, $lead);

        foreach ($attachments as $attachment) {
            $this->createPendingAttachmentAndDispatch($lead, LeadSourceType::FacebookMessenger, $attachment, $senderId, $source, $sentAt);
        }

        event(new LeadUpdated($lead));
    }

    /**
     * Look up a Facebook user's display name and profile picture via the
     * Graph API using the lead source's page access token. Cached for 24h
     * per PSID once successful — only successful lookups are cached, so a
     * failed one (bad token, transient Graph API error) doesn't get "stuck"
     * for a day; it's retried the next time this sender messages in, via
     * appendFacebookMessage()'s name-backfill check.
     *
     * @return array{name: ?string, profile_pic: ?string}
     */
    private function fetchFacebookProfile(LeadSource $source, string $psid): array
    {
        $cacheKey = "fb_profile_{$psid}";
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return [
                'name' => is_string($cached['name'] ?? null) ? $cached['name'] : null,
                'profile_pic' => is_string($cached['profile_pic'] ?? null) ? $cached['profile_pic'] : null,
            ];
        }

        $empty = ['name' => null, 'profile_pic' => null];

        $accessToken = $source->config['page_access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            return $empty;
        }

        try {
            $response = Http::timeout(5)->get("https://graph.facebook.com/{$psid}", [
                'fields' => 'first_name,last_name,name,profile_pic',
                'access_token' => $accessToken,
            ]);
        } catch (ConnectionException $e) {
            Log::warning("Facebook profile lookup for psid [{$psid}] could not connect: {$e->getMessage()}");

            return $empty;
        }

        if (! $response->successful()) {
            Log::warning("Facebook profile lookup failed for psid [{$psid}]: HTTP {$response->status()} {$response->body()}");

            return $empty;
        }

        $name = $response->json('name');

        if (! is_string($name) || $name === '') {
            $fullName = trim(($response->json('first_name') ?? '').' '.($response->json('last_name') ?? ''));
            $name = $fullName !== '' ? $fullName : null;
        }

        $profilePic = $response->json('profile_pic');

        $profile = [
            'name' => $name,
            'profile_pic' => is_string($profilePic) && $profilePic !== '' ? $profilePic : null,
        ];

        if ($name !== null) {
            Cache::put($cacheKey, $profile, now()->addHours(24));
        }

        return $profile;
    }

    /**
     * Append a follow-up Instagram DM from the same user onto their existing
     * lead, instead of creating a duplicate lead per message.
     *
     * @param  mixed  $messaging
     * @param  array<int, array{type: string, url: string}>  $attachments
     */
    private function appendInstagramMessage(Lead $lead, ?string $messageText, $messaging, array $attachments, string $senderId, LeadSource $source, CarbonInterface $sentAt): void
    {
        $sourceData = $lead->source_data ?? [];

        if ($lead->name === "Instagram User {$senderId}") {
            $profile = $this->fetchInstagramProfile($source, $senderId);

            if ($profile['name'] !== null) {
                $lead->name = $profile['name'];
                $sourceData['instagram_profile_pic'] = $profile['profile_pic'];
            }
        }

        if ($messageText !== null && $messageText !== '') {
            $lead->message = trim(($lead->message ?? '')."\n".$messageText);
        }

        $sourceData['messages'][] = $messaging;
        $lead->source_data = $sourceData;

        $lead->save();

        $this->logIfMultipleAttachments($attachments, $lead);

        foreach ($attachments as $attachment) {
            $this->createPendingAttachmentAndDispatch($lead, LeadSourceType::Instagram, $attachment, $senderId, $source, $sentAt);
        }

        event(new LeadUpdated($lead));
    }

    /**
     * Append a follow-up WhatsApp message from the same sender onto their
     * existing lead, instead of creating a duplicate lead per message —
     * mirrors appendFacebookMessage()/appendInstagramMessage() for a
     * platform that otherwise has no natural "conversation" concept.
     *
     * @param  array<string, mixed>  $message
     * @param  array{type: string, media_id: string, caption: ?string}|null  $attachment
     */
    private function appendWhatsappMessage(Lead $lead, ?string $messageText, array $message, ?array $attachment, string $senderId, LeadSource $source, CarbonInterface $sentAt): void
    {
        if ($messageText !== null && $messageText !== '') {
            $lead->message = trim(($lead->message ?? '')."\n".$messageText);
        }

        $sourceData = $lead->source_data ?? [];
        $sourceData['messages'][] = $message;
        $lead->source_data = $sourceData;

        $lead->save();

        if ($attachment !== null) {
            $this->createPendingAttachmentAndDispatch($lead, LeadSourceType::Whatsapp, $attachment, $senderId, $source, $sentAt);
        }

        event(new LeadUpdated($lead));
    }

    /**
     * Look up an Instagram DM sender's display name and profile picture via
     * the Graph API. The host depends on how the source authenticated:
     * `graph.facebook.com` for a linked Facebook Page's access token, or
     * `graph.instagram.com` for a token from Instagram Login (no Page).
     * Cached for 24h per IGSID once successful — see fetchFacebookProfile()
     * for why failures aren't cached.
     *
     * @return array{name: ?string, profile_pic: ?string}
     */
    private function fetchInstagramProfile(LeadSource $source, string $igsid): array
    {
        $cacheKey = "ig_profile_{$igsid}";
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return [
                'name' => is_string($cached['name'] ?? null) ? $cached['name'] : null,
                'profile_pic' => is_string($cached['profile_pic'] ?? null) ? $cached['profile_pic'] : null,
            ];
        }

        $empty = ['name' => null, 'profile_pic' => null];

        $accessToken = $source->config['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            return $empty;
        }

        $host = ($source->config['auth_mode'] ?? 'facebook') === 'instagram'
            ? 'graph.instagram.com'
            : 'graph.facebook.com';

        try {
            $response = Http::timeout(5)->get("https://{$host}/{$igsid}", [
                'fields' => 'name,username,profile_pic',
                'access_token' => $accessToken,
            ]);
        } catch (ConnectionException $e) {
            Log::warning("Instagram profile lookup for igsid [{$igsid}] could not connect: {$e->getMessage()}");

            return $empty;
        }

        if (! $response->successful()) {
            Log::warning("Instagram profile lookup failed for igsid [{$igsid}]: HTTP {$response->status()} {$response->body()}");

            return $empty;
        }

        $name = $response->json('name') ?? $response->json('username');
        $profilePic = $response->json('profile_pic');

        $profile = [
            'name' => is_string($name) && $name !== '' ? $name : null,
            'profile_pic' => is_string($profilePic) && $profilePic !== '' ? $profilePic : null,
        ];

        if ($profile['name'] !== null) {
            Cache::put($cacheKey, $profile, now()->addHours(24));
        }

        return $profile;
    }

    /**
     * Extract every downloadable attachment from a Messenger/Instagram
     * `message.attachments[]` entry (both platforms share this shape). A
     * single message can carry several — e.g. sending multiple photos at
     * once — so all of them are returned, not just the first. Stickers and
     * non-media attachment types (templates, location shares, fallbacks) are
     * left out — they don't carry a `payload.url` to fetch.
     *
     * @param  mixed  $messaging
     * @return array<int, array{type: string, url: string}>
     */
    private function extractMessagingAttachments($messaging): array
    {
        $attachments = $messaging['message']['attachments'] ?? [];
        $extracted = [];

        foreach ($attachments as $attachment) {
            $type = $attachment['type'] ?? null;
            $url = $attachment['payload']['url'] ?? null;

            if (in_array($type, ['image', 'video', 'audio', 'file'], true) && is_string($url) && $url !== '') {
                $extracted[] = ['type' => $type, 'url' => $url];
            }
        }

        return $extracted;
    }

    /**
     * Extract the media reference from a WhatsApp Cloud API message. Unlike
     * Messenger/Instagram, WhatsApp never includes a direct URL — only a
     * media id that must be separately resolved (and is only valid for a
     * limited time), which `DownloadLeadAttachment` handles.
     *
     * @param  array<string, mixed>  $message
     * @return array{type: string, media_id: string, caption: ?string}|null
     */
    private function extractWhatsappAttachment(array $message): ?array
    {
        $type = $message['type'] ?? null;

        if (! in_array($type, ['image', 'video', 'audio', 'document', 'sticker'], true)) {
            return null;
        }

        $mediaId = $message[$type]['id'] ?? null;

        if (! is_string($mediaId) || $mediaId === '') {
            return null;
        }

        return [
            'type' => $type,
            'media_id' => $mediaId,
            'caption' => $message[$type]['caption'] ?? null,
        ];
    }

    /**
     * WhatsApp includes the sender's own display name alongside the message,
     * in `value.contacts[]` (keyed by phone/wa_id) — unlike Messenger/
     * Instagram, no separate Graph API lookup is needed or possible here.
     *
     * @param  array<string, mixed>  $change
     */
    private function whatsappContactName(array $change, ?string $senderPhone): ?string
    {
        if ($senderPhone === null) {
            return null;
        }

        foreach (($change['value']['contacts'] ?? []) as $contact) {
            if (($contact['wa_id'] ?? null) !== $senderPhone) {
                continue;
            }

            $name = $contact['profile']['name'] ?? null;

            return is_string($name) && $name !== '' ? $name : null;
        }

        return null;
    }

    /**
     * Creates the `LeadAttachment` row up front (status=Downloading) and
     * dispatches the job that fills it in. Creating the row synchronously —
     * rather than letting the job create it once the download finishes —
     * means a message with several attachments never loses any of them to a
     * "last write wins" race, and a failed download stays visible on the
     * lead (status=Failed) instead of vanishing silently.
     *
     * @param  array{type: string, url: string}|array{type: string, media_id: string, caption: ?string}  $attachment
     */
    private function createPendingAttachmentAndDispatch(Lead $lead, LeadSourceType $type, array $attachment, string $senderId, LeadSource $source, CarbonInterface $sentAt): void
    {
        $accessToken = $source->config['access_token'] ?? $source->config['page_access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            return;
        }

        $pending = $lead->attachments()->create([
            'type' => $attachment['type'],
            'status' => LeadAttachmentStatus::Downloading,
            'sent_at' => $sentAt,
        ]);

        DownloadLeadAttachment::dispatch(
            attachment: $pending,
            senderId: $senderId,
            accessToken: $accessToken,
            platform: $type->value,
            remoteUrl: $attachment['url'] ?? null,
            mediaId: $attachment['media_id'] ?? null,
            whatsappPhoneNumberId: $type === LeadSourceType::Whatsapp ? ($source->config['phone_number_id'] ?? null) : null,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $attachments
     */
    private function logIfMultipleAttachments(array $attachments, Lead $lead): void
    {
        if (count($attachments) > 1) {
            Log::info('Multiple attachments received', ['count' => count($attachments), 'lead_id' => $lead->id]);
        }
    }

    /**
     * Messenger/Instagram deliver `timestamp` as milliseconds since the
     * epoch; falls back to "now" if it's missing so ordering degrades
     * gracefully instead of sorting a malformed payload to 1970.
     *
     * @param  mixed  $messaging
     */
    private function messagingTimestamp($messaging): CarbonInterface
    {
        return isset($messaging['timestamp'])
            ? Carbon::createFromTimestampMs((int) $messaging['timestamp'])
            : now();
    }

    /**
     * Ingests a Facebook Lead Ads (Instant Forms) submission. Unlike a
     * Messenger event, a `leadgen` change carries no field data itself —
     * only a `leadgen_id` — so the actual submitted fields have to be
     * fetched separately via the Graph API using the Page's access token.
     *
     * Deliberately swallows every failure internally and never throws: this
     * runs inside the same request as Messenger event processing (they
     * share one webhook URL), and a malformed or permission-denied lead ad
     * must not take down the rest of that request or cause Meta to retry
     * the whole delivery.
     *
     * @param  array<string, mixed>  $value
     */
    private function handleFacebookLeadAd(string $tenantCode, array $value): void
    {
        try {
            $this->processFacebookLeadAd($tenantCode, $value);
        } catch (Throwable $e) {
            Log::error("Facebook lead ad processing failed for tenant [{$tenantCode}]: {$e->getMessage()}");
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function processFacebookLeadAd(string $tenantCode, array $value): void
    {
        $leadgenId = $value['leadgen_id'] ?? null;

        if (! is_string($leadgenId) || $leadgenId === '') {
            Log::warning("Facebook lead ad webhook for tenant [{$tenantCode}] had no leadgen_id.");

            return;
        }

        $source = $this->resolveSourceOrNull($tenantCode, LeadSourceType::FacebookLeadAds);

        if ($source === null) {
            Log::error("Facebook lead ad [{$leadgenId}] received for tenant [{$tenantCode}], but no active Facebook Lead Ads source is configured.");

            return;
        }

        // Meta can redeliver the same leadgen event (retries, duplicate
        // subscriptions) — a lead ad submission is immutable once made, so
        // unlike Messenger's self-healing retry-on-follow-up, a duplicate
        // delivery is just skipped rather than re-fetched.
        $alreadyProcessed = Lead::query()
            ->where('source_id', $source->id)
            ->where('source_data->leadgen_id', $leadgenId)
            ->exists();

        if ($alreadyProcessed) {
            Log::info("Facebook lead ad [{$leadgenId}] already processed for tenant [{$tenantCode}]; skipping duplicate webhook delivery.");

            return;
        }

        $accessToken = $source->config['page_access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            Log::error("Facebook lead ad [{$leadgenId}] for tenant [{$tenantCode}]: no Page Access Token configured on the Lead Ads source.");

            return;
        }

        $fetched = $this->fetchFacebookLeadgenData($leadgenId, $accessToken);
        $mapped = $fetched !== null ? $this->mapLeadgenFieldData($fetched['field_data']) : ['name' => null, 'email' => null, 'phone' => null];

        $formId = $value['form_id'] ?? null;
        $formName = is_string($formId) ? $this->fetchFacebookFormName($formId, $accessToken) : null;

        $lead = $this->createLead($source, [
            'name' => $mapped['name'] ?? ('Facebook Lead '.substr($leadgenId, -4)),
            'email' => $mapped['email'],
            'phone' => $mapped['phone'],
            'source_data' => [
                'leadgen_id' => $leadgenId,
                'page_id' => $value['page_id'] ?? null,
                'form_id' => $formId,
                'form_name' => $formName,
                'ad_id' => $fetched['ad_id'] ?? ($value['ad_id'] ?? null),
                'field_data' => $fetched['field_data'] ?? null,
                'fetch_failed' => $fetched === null,
            ],
        ]);

        Log::info("Facebook lead ad [{$leadgenId}] created lead [{$lead->id}] for tenant [{$tenantCode}].");
    }

    /**
     * Fetches a lead ad's submitted field data via the Graph API. Returns
     * null on any failure — the caller falls back to a minimal lead keyed
     * only by leadgen_id, so a permission or connectivity problem doesn't
     * mean losing the lead entirely, just its field data.
     *
     * @return array{field_data: array<int, array{name?: string, values?: array<int, string>}>, ad_id: ?string}|null
     */
    private function fetchFacebookLeadgenData(string $leadgenId, string $accessToken): ?array
    {
        try {
            $response = Http::timeout(10)->get("https://graph.facebook.com/v20.0/{$leadgenId}", [
                'access_token' => $accessToken,
            ]);
        } catch (ConnectionException $e) {
            Log::warning("Facebook lead ad [{$leadgenId}] fetch could not connect: {$e->getMessage()}");

            return null;
        }

        if (! $response->successful()) {
            $code = (int) $response->json('error.code');
            $subcode = (int) $response->json('error.error_subcode');

            if ($code === 100 && $subcode === 33) {
                Log::warning("Facebook lead ad [{$leadgenId}] fetch denied — leads_retrieval needs Advanced Access, or the submitter isn't added as a Tester/Developer on the app.");
            } else {
                Log::warning("Facebook lead ad [{$leadgenId}] fetch failed: HTTP {$response->status()} {$response->body()}");
            }

            return null;
        }

        return [
            'field_data' => $response->json('field_data') ?? [],
            'ad_id' => $response->json('ad_id'),
        ];
    }

    /**
     * The lead ad's form name isn't in the webhook payload or the leadgen
     * fetch — a second, optional Graph API call. Failure just leaves the
     * name unset; it's not worth losing the lead over.
     */
    private function fetchFacebookFormName(string $formId, string $accessToken): ?string
    {
        try {
            $response = Http::timeout(10)->get("https://graph.facebook.com/v20.0/{$formId}", [
                'fields' => 'name',
                'access_token' => $accessToken,
            ]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $name = $response->json('name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Facebook Lead Ads field names aren't fully standardized across form
     * builders — this covers the common field-name variants Meta's own
     * Instant Forms templates use. Nothing is dropped: the raw field_data
     * is always kept in source_data regardless of what this recognizes.
     *
     * @param  array<int, array{name?: string, values?: array<int, string>}>  $fieldData
     * @return array{name: ?string, email: ?string, phone: ?string}
     */
    private function mapLeadgenFieldData(array $fieldData): array
    {
        $values = [];

        foreach ($fieldData as $field) {
            $key = strtolower((string) ($field['name'] ?? ''));
            $value = $field['values'][0] ?? null;

            if ($key !== '' && is_string($value) && $value !== '') {
                $values[$key] = $value;
            }
        }

        $firstLast = trim(($values['first_name'] ?? '').' '.($values['last_name'] ?? ''));

        return [
            'name' => $values['full_name'] ?? ($firstLast !== '' ? $firstLast : null) ?? $values['email'] ?? null,
            'email' => $values['email'] ?? null,
            'phone' => $values['phone_number'] ?? $values['phone'] ?? null,
        ];
    }
}
