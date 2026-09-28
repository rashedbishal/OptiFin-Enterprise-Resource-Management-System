<?php

namespace App\Http\Controllers\Api;

use App\Actions\Tenant\RecordInboundCall;
use App\Actions\Tenant\RecordInboundMessage;
use App\Actions\Tenant\RecordLeadConsent;
use App\Actions\Tenant\SaveLeadCustomFieldValues;
use App\Actions\Tenant\ValidateWebsiteFormSubmission;
use App\Enums\ConsentSource;
use App\Enums\ConsentStatus;
use App\Enums\LeadAttachmentStatus;
use App\Enums\LeadSourceType;
use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\TenantStatus;
use App\Events\LeadReceived;
use App\Events\LeadUpdated;
use App\Http\Controllers\Controller;
use App\Jobs\DownloadLeadAttachment;
use App\Jobs\ReconcileOutboundMessageStatus;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\WebsiteForm;
use App\Support\WhatsAppCallEvent;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
     *
     * Always acknowledges with 200, even when processing fails — mirrors
     * whatsapp()'s own reasoning: Meta disables a webhook subscription
     * after enough non-200 responses, so an inactive tenant/source, a
     * transient DB hiccup, or a lock timeout under contention (see
     * withDedupeLock()) must not surface as a 404/500 here.
     */
    public function facebookMessenger(Request $request, string $tenant_code): JsonResponse
    {
        $entries = $request->array('entry');

        if ($this->exceedsInboundMessageCap($this->countMessagingItems($entries))) {
            Log::warning("Rejected Facebook Messenger webhook for tenant [{$tenant_code}]: inbound message cap exceeded.");

            return response()->json(['status' => 'ok']);
        }

        try {
            $source = null;

            foreach ($entries as $entry) {
                foreach (($entry['messaging'] ?? []) as $messaging) {
                    $source ??= $this->resolveSource($tenant_code, LeadSourceType::FacebookMessenger);

                    // REL-1 fix: this item's own sender/message identifiers
                    // are resolved BEFORE the per-item try block below, not
                    // inside it, so they're always available for the catch's
                    // log line even if the exception originates from the
                    // very first statement inside the try (e.g. a lock
                    // timeout, which happens before any of this item's own
                    // business logic runs).
                    $senderId = (string) ($messaging['sender']['id'] ?? 'unknown');
                    $externalMessageId = $this->extractInboundMessageId($messaging);

                    // REL-1 fix: per-item exception isolation, mirroring
                    // App\Jobs\ProcessEmailLeadSource::handle()'s own
                    // per-message try/catch inside its foreach — a failure
                    // processing ONE messaging[] item (a lock timeout, a DB
                    // error) must not silently drop every item after it in
                    // the same request. Reusing the exact same outer-catch
                    // log wording pattern already established elsewhere in
                    // this file, just scoped to one item instead of the
                    // whole request. Deliberately does NOT also wrap the
                    // separate changes[]/leadgen loop below — that already
                    // has its own per-item protection via
                    // handleFacebookLeadAd()'s internal try/catch.
                    try {
                        // Feature #15 — a `messaging[]` entry is either a
                        // message, a delivery receipt, or a read receipt, never
                        // more than one — handled and dispatched for
                        // reconciliation separately from message processing
                        // below, then skipped.
                        if (isset($messaging['delivery']) || isset($messaging['read'])) {
                            $this->dispatchMessengerStatusReconciliation($source, MessageChannel::Messenger, $messaging);

                            continue;
                        }

                        $messageText = $messaging['message']['text'] ?? null;
                        $attachments = $this->extractMessagingAttachments($messaging);
                        $sentAt = $this->messagingTimestamp($messaging);

                        $this->withDedupeLock($source->id, $senderId, function () use ($source, $senderId, $messageText, $messaging, $attachments, $sentAt, $externalMessageId): void {
                            if ($this->alreadyRecordedMessage($source, MessageChannel::Messenger, $externalMessageId)) {
                                return;
                            }

                            $existingLead = $this->existingLeadForConversation($source, MessageChannel::Messenger, $senderId)
                                ?? Lead::query()
                                    ->where('source_id', $source->id)
                                    ->where('source_data->facebook_user_id', $senderId)
                                    ->whereNull('merged_into_lead_id')
                                    ->first();

                            if ($existingLead instanceof Lead) {
                                $this->appendFacebookMessage($existingLead, $messageText, $messaging, $attachments, $senderId, $source, $sentAt);

                                $source->update(['last_synced_at' => now()]);

                                return;
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

                            $this->recordInboundMessage($lead, $source, MessageChannel::Messenger, $senderId, $messageText, $messaging);

                            $this->logIfMultipleAttachments($attachments, $lead);

                            foreach ($attachments as $attachment) {
                                $this->createPendingAttachmentAndDispatch($lead, LeadSourceType::FacebookMessenger, $attachment, $senderId, $source, $sentAt);
                            }
                        });
                    } catch (Throwable $e) {
                        Log::error("Facebook Messenger webhook item processing failed for tenant [{$tenant_code}], sender [{$senderId}], message [{$externalMessageId}]: {$e->getMessage()}");
                    }
                }

                foreach (($entry['changes'] ?? []) as $change) {
                    if (($change['field'] ?? null) === 'leadgen') {
                        $this->handleFacebookLeadAd($tenant_code, $change['value'] ?? []);
                    }
                }
            }
        } catch (Throwable $e) {
            Log::error("Facebook Messenger webhook processing failed for tenant [{$tenant_code}]: {$e->getMessage()}");
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
     *
     * Always acknowledges with 200, even when processing fails — see
     * facebookMessenger()'s docblock for why.
     */
    public function instagram(Request $request, string $tenant_code): JsonResponse
    {
        $entries = $request->array('entry');

        if ($this->exceedsInboundMessageCap($this->countMessagingItems($entries))) {
            Log::warning("Rejected Instagram webhook for tenant [{$tenant_code}]: inbound message cap exceeded.");

            return response()->json(['status' => 'ok']);
        }

        try {
            $source = $this->resolveSource($tenant_code, LeadSourceType::Instagram);

            foreach ($entries as $entry) {
                foreach (($entry['messaging'] ?? []) as $messaging) {
                    // REL-1 fix: identifiers resolved before the per-item
                    // try block — see facebookMessenger()'s identical
                    // comment for why.
                    $senderId = (string) ($messaging['sender']['id'] ?? 'unknown');
                    $externalMessageId = $this->extractInboundMessageId($messaging);

                    // REL-1 fix: per-item exception isolation — see
                    // facebookMessenger()'s identical comment for the full
                    // reasoning.
                    try {
                        // Feature #15 — see facebookMessenger()'s identical
                        // check; Instagram Messaging shares the same
                        // entry[].messaging[] delivery/read shape.
                        if (isset($messaging['delivery']) || isset($messaging['read'])) {
                            $this->dispatchMessengerStatusReconciliation($source, MessageChannel::Instagram, $messaging);

                            continue;
                        }

                        $messageText = $messaging['message']['text'] ?? null;
                        $attachments = $this->extractMessagingAttachments($messaging);
                        $sentAt = $this->messagingTimestamp($messaging);

                        $this->withDedupeLock($source->id, $senderId, function () use ($source, $senderId, $messageText, $messaging, $attachments, $sentAt, $externalMessageId): void {
                            if ($this->alreadyRecordedMessage($source, MessageChannel::Instagram, $externalMessageId)) {
                                return;
                            }

                            $existingLead = $this->existingLeadForConversation($source, MessageChannel::Instagram, $senderId)
                                ?? Lead::query()
                                    ->where('source_id', $source->id)
                                    ->where('source_data->instagram_user_id', $senderId)
                                    ->whereNull('merged_into_lead_id')
                                    ->first();

                            if ($existingLead instanceof Lead) {
                                $this->appendInstagramMessage($existingLead, $messageText, $messaging, $attachments, $senderId, $source, $sentAt);

                                $source->update(['last_synced_at' => now()]);

                                return;
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

                            $this->recordInboundMessage($lead, $source, MessageChannel::Instagram, $senderId, $messageText, $messaging);

                            $this->logIfMultipleAttachments($attachments, $lead);

                            foreach ($attachments as $attachment) {
                                $this->createPendingAttachmentAndDispatch($lead, LeadSourceType::Instagram, $attachment, $senderId, $source, $sentAt);
                            }
                        });
                    } catch (Throwable $e) {
                        Log::error("Instagram webhook item processing failed for tenant [{$tenant_code}], sender [{$senderId}], message [{$externalMessageId}]: {$e->getMessage()}");
                    }
                }
            }
        } catch (Throwable $e) {
            Log::error("Instagram webhook processing failed for tenant [{$tenant_code}]: {$e->getMessage()}");
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
        // Privacy/retention fix (L1-shaped finding): a minimal, non-payload
        // diagnostic summary — never the raw request body, headers, message
        // text, or full phone number, none of which any code/test/runbook
        // in this app actually depends on (see the finding's Impact
        // Analysis) — replaces what used to be a full $request->all()/
        // headers dump on every single inbound call, written unconditionally
        // to an unrotated log file.
        $incomingSummary = $this->summarizeWhatsappIncoming($request, $tenant_code);

        Log::channel('whatsapp')->info('WA INCOMING', $incomingSummary);

        // L2 — reuses summarizeWhatsappIncoming()'s already-computed
        // message list rather than walking the payload a second time; its
        // 'messages' entry is already exactly one row per value.messages[]
        // item (never value.statuses[]), so no separate counting pass is
        // needed for this channel.
        if ($this->exceedsInboundMessageCap(count($incomingSummary['messages']))) {
            Log::channel('whatsapp')->warning("Rejected WhatsApp webhook for tenant [{$tenant_code}]: inbound message cap exceeded.");

            return response()->json(['status' => 'ok']);
        }

        try {
            $source = $this->resolveSource($tenant_code, LeadSourceType::Whatsapp);
            $entries = $request->array('entry');
            $callsWithinCap = $this->whatsappCallsWithinCap($entries, $tenant_code);

            foreach ($entries as $entry) {
                foreach (($entry['changes'] ?? []) as $change) {
                    // Feature #15 — the Cloud API's own delivery/read/failed
                    // status callback for a message THIS app sent, keyed
                    // reliably by wamid — additive, alongside the existing
                    // inbound `messages[]` handling below, never replacing
                    // it. Not previously parsed by this method at all.
                    foreach (($change['value']['statuses'] ?? []) as $status) {
                        $this->dispatchWhatsappStatusReconciliation($source, $status);
                    }

                    foreach (($change['value']['messages'] ?? []) as $message) {
                        $attachment = $this->extractWhatsappAttachment($message);
                        $senderId = (string) ($message['from'] ?? 'unknown');
                        $senderPhone = $message['from'] ?? null;
                        $messageText = $message['text']['body'] ?? $attachment['caption'] ?? null;
                        $sentAt = isset($message['timestamp'])
                            ? Carbon::createFromTimestamp((int) $message['timestamp'])
                            : now();
                        $externalMessageId = $this->extractInboundMessageId($message);

                        $handleMessage = function () use ($source, $senderPhone, $senderId, $messageText, $message, $attachment, $sentAt, $change, $externalMessageId): void {
                            if ($this->alreadyRecordedMessage($source, MessageChannel::Whatsapp, $externalMessageId)) {
                                return;
                            }

                            $existingLead = $this->existingLeadForConversation($source, MessageChannel::Whatsapp, $senderPhone)
                                ?? (is_string($senderPhone) && $senderPhone !== ''
                                    ? Lead::query()->where('source_id', $source->id)->where('phone', $senderPhone)->whereNull('merged_into_lead_id')->first()
                                    : null);

                            if ($existingLead instanceof Lead) {
                                $this->appendWhatsappMessage($existingLead, $messageText, $message, $attachment, $senderId, $source, $sentAt);

                                $source->update(['last_synced_at' => now()]);

                                return;
                            }

                            $contactName = $this->whatsappContactName($change, $senderPhone);

                            $lead = $this->createLead($source, [
                                'name' => $contactName ?? ('WhatsApp User '.$senderId),
                                'phone' => $senderPhone,
                                'message' => $messageText,
                                'source_data' => ['messages' => [$message]],
                            ]);

                            $this->recordInboundMessage($lead, $source, MessageChannel::Whatsapp, $senderPhone, $messageText, $message);

                            if ($attachment !== null) {
                                $this->createPendingAttachmentAndDispatch($lead, LeadSourceType::Whatsapp, $attachment, $senderId, $source, $sentAt);
                            }
                        };

                        // REL-1 fix: per-item exception isolation — see
                        // facebookMessenger()'s identical comment for the
                        // full reasoning. Deliberately does NOT also wrap
                        // the separate statuses[] reconciliation loop above
                        // — REL-1 is scoped to inbound MESSAGE items, per
                        // the finding's own title; statuses[] is a distinct
                        // loop over a distinct array, not part of the same
                        // per-message-item boundary.
                        try {
                            // No phone means no possible dedupe key to lock on
                            // (the lookup above already returns null unconditionally
                            // in that case) — run straight through rather than
                            // locking on a shared placeholder key that would
                            // needlessly serialize unrelated senders.
                            if (is_string($senderPhone) && $senderPhone !== '') {
                                $this->withDedupeLock($source->id, $senderPhone, $handleMessage);
                            } else {
                                $handleMessage();
                            }
                        } catch (Throwable $e) {
                            Log::channel('whatsapp')->error('WA ITEM PROCESSING FAILED', [
                                'tenant_code' => $tenant_code,
                                'sender_id' => $senderId,
                                'external_message_id' => $externalMessageId,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    // WhatsApp inbound calling (Step 2) — purely additive: a
                    // distinct `calls[]` array beside the statuses[]/messages[]
                    // loops above, which it never reads or changes. It carries
                    // its own per-item isolation and can never throw out of
                    // here, so it cannot affect the rest of this payload.
                    if ($callsWithinCap) {
                        $this->processWhatsappCalls($source, $tenant_code, $change);
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

        $this->assertAllowedWebsiteFormDomain($request, $source);

        $name = $request->input('name', 'Website Visitor');
        $email = $request->input('email');
        $phone = $request->input('phone');
        $message = $request->input('message');
        $extra = $request->except(['name', 'email', 'phone', 'message']);

        // REL-2 fix: see withWebsiteFormIdempotency()'s own docblock — a
        // double-click or client-side retry submitting this exact same
        // content again within the short window is not a second lead.
        $this->withWebsiteFormIdempotency(
            $source->tenant_id,
            $source->id,
            [
                'name' => $name,
                'email' => is_string($email) ? mb_strtolower($email) : $email,
                'phone' => $phone,
                'message' => $message,
                'extra' => $extra,
            ],
            fn (): Lead => $this->createLead($source, [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'message' => $message,
                'source_data' => $extra,
            ]),
        );

        return response()->json(['status' => 'ok']);
    }

    /**
     * Feature #13 — handle a submission from a tenant-designed Website
     * Form, authenticated by its own public token rather than a source-wide
     * API key. Deliberately a separate method from websiteForm() above,
     * calling a different resolution path — the legacy method and its
     * whole behavior contract are untouched by this addition. See
     * App\Actions\Tenant\ValidateWebsiteFormSubmission for why unconfigured
     * fields are discarded here, unlike the legacy path's "keep everything
     * extra" behavior.
     */
    public function websiteFormSubmit(Request $request, string $tenant_code, string $form_token): JsonResponse
    {
        $form = $this->resolveWebsiteForm($tenant_code, $form_token);

        $result = app(ValidateWebsiteFormSubmission::class)->handle($form, $request->all());

        // A honeypot hit returns the same success response a real
        // submission would — never tipping off whatever filled it in that
        // anything was detected — but creates nothing.
        if ($result['honeypot']) {
            return response()->json(['status' => 'ok']);
        }

        $email = $result['attributes']['email'] ?? null;

        // REL-2 fix: see withWebsiteFormIdempotency()'s own docblock. The
        // fingerprint covers the entire logical submission — Lead creation
        // AND consent recording together, both inside $create() below — so
        // a duplicate request skips both, never just the Lead.
        $this->withWebsiteFormIdempotency(
            $form->tenant_id,
            $form->id,
            [
                'name' => $result['attributes']['name'],
                'email' => is_string($email) ? mb_strtolower($email) : $email,
                'phone' => $result['attributes']['phone'] ?? null,
                'message' => $result['attributes']['message'] ?? null,
                'customFieldValues' => $result['customFieldValues'],
                'consentGrants' => array_map(fn (array $grant): string => $grant['type']->value, $result['consentGrants']),
            ],
            function () use ($request, $form, $result): Lead {
                $lead = $this->createLead(
                    $form->leadSource,
                    array_merge($result['attributes'], [
                        // A point-in-time snapshot, never a hard FK from
                        // Lead to WebsiteForm — see WebsiteForm's own
                        // docblock for why: deleting a form must never
                        // touch any existing Lead.
                        'source_data' => [
                            'website_form_id' => $form->id,
                            'website_form_name' => $form->name,
                        ],
                    ]),
                    $result['customFieldValues'],
                );

                // Feature #14 — recorded AFTER the Lead exists, once per
                // configured and explicitly-granted consent type.
                // $result['consentGrants'] only ever contains types
                // ValidateWebsiteFormSubmission derived from this specific
                // form's own server-stored field configuration and
                // validated as an explicit true — never anything
                // client-controlled.
                foreach ($result['consentGrants'] as $grant) {
                    app(RecordLeadConsent::class)->handle(
                        lead: $lead,
                        type: $grant['type'],
                        status: ConsentStatus::Granted,
                        source: ConsentSource::WebsiteForm,
                        websiteFormId: $form->id,
                        websiteFormName: $form->name,
                        policyVersion: $grant['policyVersion'],
                        policyUrl: $grant['policyUrl'],
                        ipAddress: $request->ip(),
                        userAgent: $request->userAgent(),
                    );
                }

                return $lead;
            },
        );

        return response()->json(['status' => 'ok']);
    }

    /**
     * Resolve a specific Website Form by its public token — never by type,
     * unlike resolveSource() above, since a tenant may have any number of
     * these. The token itself is the sole credential; a mismatched
     * tenant_code/token pairing (or an inactive form, or an unknown token)
     * all fail identically via a 404, so a probing request never learns
     * which part of the pair was wrong.
     */
    private function resolveWebsiteForm(string $tenantCode, string $formToken): WebsiteForm
    {
        $tenant = Tenant::where('tenant_code', $tenantCode)
            ->where('status', TenantStatus::Active)
            ->first();

        $form = WebsiteForm::where('token_hash', hash('sha256', $formToken))
            ->where('is_active', true)
            ->first();

        // One plain 404 for every failure — never firstOrFail(), whose
        // exception message would name the model that did not resolve.
        abort_if($tenant === null || $form === null || $form->tenant_id !== $tenant->id, 404);

        return $form;
    }

    /**
     * REL-2 fix: idempotency guard for a website-form submission — both
     * websiteForm() (legacy, API-key) and websiteFormSubmit() (builder,
     * per-form token) funnel through here. A short-lived cache lock plus a
     * short-lived durable "already processed" marker, mirroring
     * withDedupeLock()/alreadyRecordedMessage()'s own "lock, then check a
     * durable marker before doing the work" idiom — with one deliberate
     * difference: the marker EXPIRES rather than lasting forever. Unlike a
     * Messenger external_message_id or a Facebook Lead Ads leadgen_id,
     * there is no stable external identifier for a website-form submission
     * to dedupe on permanently, and the same person submitting the same
     * form content again later is a legitimate, expected action — matching
     * LeadDuplicateDetectionService's own deliberate design (cross-time
     * content duplicates are a soft-linked, human-reviewed concern, never
     * something silently blocked at creation time).
     *
     * $scopeId is the LeadSource id for the legacy path (source-wide — the
     * only scoping boundary that path has) and the WebsiteForm id for the
     * builder path (per-form, tighter than its own shared source — two
     * different forms attributing to the same shared source are still two
     * different logical submissions).
     *
     * $create() must run the ENTIRE logical submission for that request —
     * Lead creation and, for the builder path, consent recording together
     * — never just createLead() alone, so a duplicate request skips both.
     * createLead() itself is untouched and called exactly as before from
     * inside $create().
     *
     * Fails OPEN on a lock-acquisition timeout: rather than silently
     * dropping a genuine submission (irrecoverable data loss) or surfacing
     * an error to a real visitor for what is, from their side, an ordinary
     * form submission, this proceeds to run $create() without idempotency
     * protection for that one request and logs a warning — a known,
     * pre-existing risk (today's un-deduplicated behavior), not a new
     * regression.
     *
     * @param  array<string, mixed>  $fingerprintContent
     * @param  callable(): ?Lead  $create
     */
    private function withWebsiteFormIdempotency(string $tenantId, string $scopeId, array $fingerprintContent, callable $create): ?Lead
    {
        $fingerprint = self::fingerprintWebsiteFormSubmission($fingerprintContent);
        $markerKey = "website-form-submission-processed:{$tenantId}:{$scopeId}:{$fingerprint}";

        try {
            return Cache::lock("{$markerKey}:lock", 10)->block(5, function () use ($markerKey, $create): ?Lead {
                if (Cache::has($markerKey)) {
                    return null;
                }

                $lead = $create();

                // 20s: long enough to catch a slow client timeout-then-
                // retry (a typical browser/mobile fetch timeout is in the
                // 10-30s range) and an ordinary double-click, short enough
                // that a visitor deliberately resubmitting the exact same
                // content on purpose is never meaningfully blocked.
                Cache::put($markerKey, true, now()->addSeconds(20));

                return $lead;
            });
        } catch (LockTimeoutException) {
            Log::warning("Website form idempotency lock timed out for tenant [{$tenantId}], scope [{$scopeId}]; proceeding without duplicate protection for this request.");

            return $create();
        }
    }

    /**
     * Deterministic content fingerprint for a website-form submission —
     * canonicalized so the same logical content always hashes identically
     * regardless of array key order, and only its hash (never the raw
     * payload) is used as a cache key. Deliberately excludes anything
     * request-specific (IP, headers, timestamps) that could legitimately
     * differ between two HTTP requests carrying the exact same logical
     * submission.
     *
     * @param  array<string, mixed>  $content
     */
    private static function fingerprintWebsiteFormSubmission(array $content): string
    {
        return hash('sha256', json_encode(
            self::canonicalizeForFingerprint($content),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Recursively sorts array keys and normalizes blank/whitespace-only
     * strings to null, so two submissions that differ only in field order
     * or incidental whitespace still fingerprint identically.
     */
    private static function canonicalizeForFingerprint(mixed $value): mixed
    {
        if (is_array($value)) {
            $canonical = [];

            foreach ($value as $key => $item) {
                $canonical[$key] = self::canonicalizeForFingerprint($item);
            }

            ksort($canonical);

            return $canonical;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return $value;
    }

    /**
     * Restricts a website form submission to the source's own
     * `allowed_domains` list, when one is configured. Deliberately opt-in
     * (same reasoning as VerifyMetaWebhookSignature's app_secret check):
     * an empty/unset list skips this entirely, so a source configured
     * before this existed — or a purely server-to-server integration that
     * never sends Origin/Referer at all — keeps working with the API key
     * alone. Once at least one domain is configured, a request must carry
     * an Origin (preferred — a browser sets this itself and client-side JS
     * cannot override it, unlike Referer) or Referer header whose host
     * matches one of them exactly or as a subdomain; a request with
     * neither header present is rejected outright, since domain
     * restriction is meaningless without something to check it against.
     *
     * Reuses LeadSource's existing last_signature_rejection_at/_reason
     * columns rather than adding new ones — that mechanism already exists
     * purely to surface an inbound-webhook rejection the admin had no live
     * session to see (Meta/the client's own server made the request), and
     * flashSignatureRejections() toasts whatever reason string is stored
     * regardless of which check produced it.
     */
    private function assertAllowedWebsiteFormDomain(Request $request, LeadSource $source): void
    {
        $allowedDomains = $source->config['allowed_domains'] ?? [];

        if (! is_array($allowedDomains) || $allowedDomains === []) {
            return;
        }

        $originHeader = $request->header('Origin') ?: $request->header('Referer');
        $parsedHost = $originHeader !== null ? parse_url($originHeader, PHP_URL_HOST) : null;
        $requestHost = is_string($parsedHost) ? $parsedHost : null;

        $allowed = false;

        if ($requestHost !== null) {
            foreach ($allowedDomains as $domain) {
                if (is_string($domain) && $this->hostMatchesDomain($requestHost, $domain)) {
                    $allowed = true;

                    break;
                }
            }
        }

        if ($allowed) {
            return;
        }

        $reason = $requestHost !== null
            ? "Website form submission rejected — origin \"{$requestHost}\" is not in this source's Allowed Domains list."
            : 'Website form submission rejected — no Origin or Referer header was sent, and this source has an Allowed Domains list configured.';

        Log::warning("Rejected website form webhook for tenant [{$source->tenant->tenant_code}]: {$reason}");

        $source->update([
            'last_signature_rejection_at' => now(),
            'last_signature_rejection_reason' => $reason,
        ]);

        abort(403);
    }

    /**
     * A configured domain matches the request host exactly or as a parent
     * domain of it (e.g. "example.com" matches "www.example.com"), not the
     * other way around. Tolerates a domain accidentally saved with a
     * scheme/path (e.g. "https://example.com/") by re-parsing it the same
     * way as the incoming header, rather than requiring the admin to
     * re-save a bare-host value.
     */
    private function hostMatchesDomain(string $requestHost, string $domain): bool
    {
        $domain = trim($domain);
        $domain = str_contains($domain, '://') ? (string) parse_url($domain, PHP_URL_HOST) : $domain;
        $domain = strtolower(trim($domain, "/. \t\n\r\0\x0B"));

        if ($domain === '') {
            return false;
        }

        $requestHost = strtolower($requestHost);

        return $requestHost === $domain || str_ends_with($requestHost, '.'.$domain);
    }

    /**
     * Resolve the active lead source of the given type for a tenant, by
     * tenant code. Rejects if the tenant itself is not active.
     *
     * Every failure (no such tenant code, an inactive tenant, no active
     * source of that type) is the SAME plain 404 — deliberately not
     * firstOrFail(), whose ModelNotFoundException message names the missing
     * model class and so tells an anonymous caller which part did not
     * resolve (a tenant_code enumeration oracle). Throwable-catching callers
     * (resolveSourceOrNull(), the always-200 chat handlers) are unaffected:
     * an HttpException is caught exactly as the model exception was.
     */
    private function resolveSource(string $tenantCode, LeadSourceType $type): LeadSource
    {
        $tenant = Tenant::where('tenant_code', $tenantCode)
            ->where('status', TenantStatus::Active)
            ->first();

        $source = $tenant === null ? null : LeadSource::query()
            ->where('tenant_id', $tenant->id)
            ->where('type', $type)
            ->where('is_active', true)
            ->first();

        abort_if($source === null, 404);

        return $source;
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
     * $customFieldValues (Feature #13) is empty for every existing caller
     * — only websiteFormSubmit() ever passes anything here, keyed by
     * lead_custom_field_definition_id (already resolved server-side by
     * ValidateWebsiteFormSubmission, never trusted from the request).
     * SaveLeadCustomFieldValues is the same, unmodified action Feature #11
     * already uses for the authenticated create/edit paths. Every existing
     * call site's behavior is unchanged: none of them pass this argument,
     * so the new step is simply never reached for them.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $customFieldValues
     */
    private function createLead(LeadSource $source, array $attributes, array $customFieldValues = []): Lead
    {
        // TOCTOU fix: coordination lock — see
        // Platform\Clients\LeadSources::toggleLeadStatus()'s own docblock
        // for the full readers/writer protocol. Wrapped in its own
        // transaction (no caller of createLead() wraps one already) so the
        // lock and the Lead::create() below commit atomically — a
        // pipeline-status removal racing this exact window can never
        // strand the lead being created. initialLeadStatus() is read from
        // this freshly-locked $tenant, never $source->tenant (which may
        // carry a stale cached relation).
        //
        // DEADLOCK-1 fix: FOR UPDATE, not FOR SHARE — Lead::create() below
        // triggers Lead::nextIdentifier(), which independently takes its
        // own FOR UPDATE on this exact same tenant row. Two concurrent
        // callers each holding FOR SHARE and then both trying to upgrade to
        // FOR UPDATE is a real, reproduced PostgreSQL deadlock (SQLSTATE
        // 40P01); taking FOR UPDATE up front instead means every caller
        // requests the same, strongest lock immediately, so contention
        // becomes ordinary serialized blocking, never a circular wait —
        // nextIdentifier()'s own FOR UPDATE then becomes a harmless re-lock
        // of a row this transaction already holds exclusively. Every
        // status-assigning writer reusing this same protocol still queues
        // behind this lock exactly as before; only the lock's own strength
        // changed, not the protocol.
        $lead = DB::transaction(function () use ($source, $attributes): Lead {
            $tenant = Tenant::whereKey($source->tenant_id)->lockForUpdate()->firstOrFail();

            return Lead::create(array_merge([
                'tenant_id' => $source->tenant_id,
                'source_id' => $source->id,
                'status_id' => $tenant->initialLeadStatus()?->id,
            ], $attributes));
        });

        if ($customFieldValues !== []) {
            app(SaveLeadCustomFieldValues::class)->handle($lead, $source->tenant_id, $customFieldValues);
        }

        $source->update(['last_synced_at' => now()]);

        event(new LeadReceived($lead));

        return $lead;
    }

    /**
     * Serializes concurrent webhook deliveries that share the same
     * source+identifier (a Messenger/Instagram PSID, a WhatsApp phone
     * number, or a Facebook Lead Ads leadgen_id) — a near-simultaneous
     * Meta redelivery could otherwise pass a "does a lead already exist
     * for this identifier" check twice and create two separate Lead rows
     * for what's really one conversation/submission, since that
     * check-then-create pattern isn't atomic on its own the way
     * Lead::nextIdentifier()'s row lock is. Held only for the duration of
     * the check+create itself, not the whole request. $lockSeconds needs to
     * outlast whatever $callback actually does — the Facebook Lead Ads path
     * chains two 10s-timeout Graph API calls before it creates anything, so
     * it passes a longer TTL than the Messenger/Instagram/WhatsApp paths
     * (which only do local DB work) need.
     */
    private function withDedupeLock(string $sourceId, string $identifier, callable $callback, int $lockSeconds = 10, int $blockSeconds = 5): mixed
    {
        return Cache::lock("lead-webhook-dedupe:{$sourceId}:{$identifier}", $lockSeconds)->block($blockSeconds, $callback);
    }

    /**
     * Feature #15 continuity fix: an existing Conversation's lead_id is the
     * authoritative owner of an ongoing external thread (see
     * LeadMergeService's own Conversation/Message reparenting) — checked
     * BEFORE the channel's own source_data/phone-based "existing lead"
     * lookup, so a lead merge doesn't cause the next inbound message on the
     * same thread to spawn a spurious new Lead once the original lookup key
     * (which is deliberately never merged onto a survivor — see
     * LeadMergeService::MERGEABLE_FIELDS) stops matching. Mirrors
     * recordInboundMessage()'s own empty/'unknown' thread-id guard exactly,
     * so this never looks up a Conversation for a thread id that method
     * would itself refuse to write.
     */
    private function existingLeadForConversation(LeadSource $source, MessageChannel $channel, ?string $externalThreadId): ?Lead
    {
        if (! is_string($externalThreadId) || $externalThreadId === '' || $externalThreadId === 'unknown') {
            return null;
        }

        return Conversation::query()
            ->where('tenant_id', $source->tenant_id)
            ->where('lead_source_id', $source->id)
            ->where('channel', $channel)
            ->where('external_thread_id', $externalThreadId)
            ->first()
            ?->lead;
    }

    /**
     * Redelivery-idempotency gate — checked as the FIRST statement inside
     * each channel's own withDedupeLock() closure, before
     * existingLeadForConversation(), before appendFacebookMessage()/
     * appendInstagramMessage()/appendWhatsappMessage(), and before
     * createLead(): Meta/WhatsApp Cloud API webhooks are documented
     * at-least-once delivery, and a genuine redelivery of the same inbound
     * message must not append a second copy onto Lead.message/
     * source_data['messages'], must not create a second normalized
     * Message row, and must not create a second LeadAttachment/dispatch a
     * second DownloadLeadAttachment job. Mirrors processFacebookLeadAd()'s
     * own alreadyProcessed check exactly — same "durable existence check,
     * first thing inside the existing sender-scoped lock" idiom, reusing
     * the real messages_external_id_unique(tenant_id, channel,
     * external_message_id) index rather than a new table/column.
     *
     * A null $externalMessageId (a payload that never carried a platform
     * message id — "purely opportunistic," per RecordInboundMessage's own
     * docblock) always returns false here, preserving today's existing,
     * already-accepted behavior for that case unchanged — never
     * synthesizing an identifier from body/sender/timestamp.
     */
    private function alreadyRecordedMessage(LeadSource $source, MessageChannel $channel, ?string $externalMessageId): bool
    {
        if ($externalMessageId === null) {
            return false;
        }

        return Message::query()
            ->where('tenant_id', $source->tenant_id)
            ->where('channel', $channel)
            ->where('external_message_id', $externalMessageId)
            ->exists();
    }

    /**
     * L2 — a single accepted webhook request must not be able to trigger
     * unbounded synchronous processing based solely on how many logical
     * messages its JSON payload claims to contain. Checked BEFORE
     * resolveSource()/existingLeadForConversation()/append*Message()/
     * createLead() — a pure count against already-parsed request data, no
     * DB/API access, no tenant resolution, no per-message work. Config-
     * driven ('webhooks.max_inbound_messages_per_request', default 100 —
     * no repository/provider evidence supports a specific number; 100
     * mirrors this app's own existing App\Livewire\Tenant\Leads\Index::
     * MAX_BULK_SELECTION precedent for "a reasonable single-request work
     * ceiling," not a documented platform limit).
     */
    private function exceedsInboundMessageCap(int $count): bool
    {
        return $count > (int) config('webhooks.max_inbound_messages_per_request');
    }

    /**
     * Counts Messenger/Instagram entry[].messaging[] items that represent
     * an actual inbound message — excluding delivery/read receipts, which
     * are cheap (no Lead/Conversation/Message work) and handled by a
     * separate code path. Summed across every entry[] in the request, not
     * per-entry: nothing stops an attacker splitting the same total
     * message count across many entries to evade a per-entry-only cap.
     *
     * @param  array<int, mixed>  $entries
     */
    private function countMessagingItems(array $entries): int
    {
        $count = 0;

        foreach ($entries as $entry) {
            foreach (($entry['messaging'] ?? []) as $messaging) {
                if (isset($messaging['delivery']) || isset($messaging['read'])) {
                    continue;
                }

                $count++;
            }
        }

        return $count;
    }

    /**
     * The approved WA INCOMING diagnostic contract (privacy/retention
     * fix, L1-shaped finding) — structural visibility only: how many
     * entries arrived and, for each message actually parseable out of
     * them, its type/id/timestamp and whether it carried body text, plus
     * the sender's phone number masked to its last 4 digits. Never the
     * message body/caption itself, never headers, never the raw
     * contacts/profile payload, never the full phone number.
     *
     * @return array<string, mixed>
     */
    private function summarizeWhatsappIncoming(Request $request, string $tenant_code): array
    {
        $entries = $request->array('entry');
        $messages = [];

        foreach ($entries as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                foreach (($change['value']['messages'] ?? []) as $message) {
                    $messages[] = [
                        'message_type' => $message['type'] ?? null,
                        'has_body' => is_string($message['text']['body'] ?? null) && $message['text']['body'] !== '',
                        'wamid' => $message['id'] ?? null,
                        'timestamp' => $message['timestamp'] ?? null,
                        'phone_last4' => $this->maskPhoneLast4($message['from'] ?? null),
                    ];
                }
            }
        }

        return [
            'tenant_code' => $tenant_code,
            'entry_count' => count($entries),
            'messages' => $messages,
        ];
    }

    private function maskPhoneLast4(mixed $phone): ?string
    {
        if (! is_string($phone) || $phone === '') {
            return null;
        }

        return '****'.substr($phone, -4);
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

        $this->recordInboundMessage($lead, $source, MessageChannel::Messenger, $senderId, $messageText, $messaging);

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

        $this->recordInboundMessage($lead, $source, MessageChannel::Instagram, $senderId, $messageText, $messaging);

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

        $this->recordInboundMessage($lead, $source, MessageChannel::Whatsapp, $senderId, $messageText, $message);

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
     * WhatsApp inbound calling (Step 2) — whether this request's `calls[]`
     * items, summed across every entry, stay within the same per-request
     * ceiling the message path applies (config webhooks.
     * max_inbound_messages_per_request), counted independently of messages so
     * the existing message cap and its exact behavior are untouched. Over the
     * ceiling, every call item in the request is skipped (logged once, by
     * count only) — the request is still acknowledged with 200.
     *
     * @param  array<mixed>  $entries
     */
    private function whatsappCallsWithinCap(array $entries, string $tenantCode): bool
    {
        $count = 0;

        foreach ($entries as $entry) {
            $changes = is_array($entry) ? ($entry['changes'] ?? null) : null;

            if (! is_array($changes)) {
                continue;
            }

            foreach ($changes as $change) {
                $calls = is_array($change) && is_array($change['value'] ?? null) ? ($change['value']['calls'] ?? null) : null;

                if (is_array($calls)) {
                    $count += count($calls);
                }
            }
        }

        if ($count === 0 || ! $this->exceedsInboundMessageCap($count)) {
            return true;
        }

        Log::channel('whatsapp')->warning('WA CALL CAP EXCEEDED', [
            'tenant_code' => $tenantCode,
            'call_count' => $count,
        ]);

        return false;
    }

    /**
     * WhatsApp inbound calling (Step 2) — runs every `calls[]` item of one
     * change through handleWhatsappCall(), each in its own try/catch so a
     * broken item is logged (class and call id only — an exception message can
     * embed a phone number or SQL bindings) and never stops the next one.
     */
    private function processWhatsappCalls(LeadSource $source, string $tenantCode, mixed $change): void
    {
        if (! is_array($change) || ! is_array($change['value'] ?? null)) {
            return;
        }

        $calls = $change['value']['calls'] ?? null;

        if (! is_array($calls)) {
            return;
        }

        foreach ($calls as $call) {
            try {
                $this->handleWhatsappCall($source, $tenantCode, $change, $call);
            } catch (Throwable $e) {
                Log::channel('whatsapp')->error('WA CALL FAILED', [
                    'tenant_code' => $tenantCode,
                    'call_id' => WhatsAppCallEvent::loggableId($call),
                    'exception' => $e::class,
                ]);
            }
        }
    }

    /**
     * WhatsApp inbound calling (Step 2) — the source-level gates for one call
     * item, then hands it to RecordInboundCall. Every ignored item is logged
     * with its reason and call id only: no payload, no SDP, no phone number.
     *
     * Gates, in order: the source must have an app_secret (calls are only
     * trusted from a source whose Meta signature is actually being verified —
     * the signature middleware is opt-in and is deliberately left unchanged);
     * the item must parse; a phone_number_id mismatch is ignored when both
     * sides are known; a valid `connect` records Meta's calling status as
     * observed-ENABLED; and calling must be enabled locally to accept a NEW
     * call (a terminate for an already-recorded call is still applied).
     *
     * @param  array<mixed>  $change
     */
    private function handleWhatsappCall(LeadSource $source, string $tenantCode, array $change, mixed $call): void
    {
        $config = $source->config ?? [];
        $appSecret = $config['app_secret'] ?? null;

        if (! is_string($appSecret) || $appSecret === '') {
            $this->logWhatsappCall($tenantCode, WhatsAppCallEvent::loggableId($call), null, 'ignored_no_app_secret');

            return;
        }

        $event = WhatsAppCallEvent::fromWebhook($change, $call);

        if (is_string($event)) {
            $this->logWhatsappCall($tenantCode, WhatsAppCallEvent::loggableId($call), null, 'ignored_'.$event);

            return;
        }

        if (! $event->matchesPhoneNumberId($config['phone_number_id'] ?? null)) {
            $this->logWhatsappCall($tenantCode, $event->callId, $event->event, 'ignored_phone_number_mismatch');

            return;
        }

        if ($event->isConnect()) {
            $this->observeWhatsappCallingStatus($source);
        }

        // Calling switched off refuses NEW calls only: a `connect` is ignored
        // here, while a `terminate` continues so an already-recorded call can
        // still be closed (RecordInboundCall ignores a terminate for an
        // unrecorded call when it is told new calls are not accepted).
        $acceptNewCalls = (bool) $source->calling_enabled;

        if (! $acceptNewCalls && $event->isConnect()) {
            $this->logWhatsappCall($tenantCode, $event->callId, $event->event, RecordInboundCall::OUTCOME_IGNORED_CALLING_DISABLED);

            return;
        }

        $outcome = app(RecordInboundCall::class)->handle($event, $source, fn (): Lead => $this->createLead($source, [
            'name' => $event->callerName ?? ('WhatsApp User '.$event->callerPhone),
            'phone' => $event->callerPhone,
            'message' => null,
            // Deliberately null: a call's payload carries the SDP offer, which
            // must never be persisted on the Lead.
            'source_data' => null,
        ]), $acceptNewCalls);

        $this->logWhatsappCall($tenantCode, $event->callId, $event->event, $outcome);
    }

    /**
     * A received `connect` proves Meta's calling is enabled for this number,
     * whatever our local flag says — recorded so the platform admin can see a
     * mismatch. Never changes calling_enabled, and skipped when the marker was
     * already refreshed within the last minute to avoid a write per webhook.
     */
    private function observeWhatsappCallingStatus(LeadSource $source): void
    {
        $checkedAt = $source->calling_meta_checked_at;

        if ($source->calling_meta_status === 'ENABLED' && $checkedAt !== null && $checkedAt->gt(Carbon::now()->subMinute())) {
            return;
        }

        $source->update([
            'calling_meta_status' => 'ENABLED',
            'calling_meta_checked_at' => Carbon::now(),
        ]);
    }

    private function logWhatsappCall(string $tenantCode, ?string $callId, ?string $event, string $outcome): void
    {
        Log::channel('whatsapp')->info('WA CALL', [
            'tenant_code' => $tenantCode,
            'call_id' => $callId,
            'event' => $event,
            'outcome' => $outcome,
        ]);
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
            platform: $type->value,
            remoteUrl: $attachment['url'] ?? null,
            mediaId: $attachment['media_id'] ?? null,
        );
    }

    /**
     * Feature #15 — the single additive dual-write call site every inbound
     * handler above goes through, alongside its own EXISTING, unmodified
     * Lead.source_data JSON append. Never replaces or removes that write —
     * see App\Actions\Tenant\RecordInboundMessage's own docblock. Swallows
     * its own failure without rethrowing: a normalized-message-store hiccup
     * must never take down inbound lead/message capture, which has already
     * fully succeeded (via the pre-existing source_data write) by the time
     * this runs.
     *
     * @param  mixed  $rawPayload
     */
    private function recordInboundMessage(Lead $lead, LeadSource $source, MessageChannel $channel, ?string $externalThreadId, ?string $body, $rawPayload): void
    {
        if (! is_string($externalThreadId) || $externalThreadId === '' || $externalThreadId === 'unknown') {
            return;
        }

        try {
            app(RecordInboundMessage::class)->handle(
                lead: $lead,
                source: $source,
                channel: $channel,
                externalThreadId: $externalThreadId,
                body: $body,
                externalMessageId: $this->extractInboundMessageId($rawPayload),
                metadata: is_array($rawPayload) ? $rawPayload : null,
            );
        } catch (Throwable $e) {
            Log::warning("Feature #15 normalized message write failed for lead [{$lead->id}]: {$e->getMessage()}");
        }
    }

    /**
     * Messenger/Instagram carry the inbound message's own id at
     * `message.mid`; WhatsApp carries it at the top-level `id`. Purely
     * opportunistic — a missing id here only means this specific inbound
     * row has no external_message_id, never a processing failure.
     *
     * @param  mixed  $rawPayload
     */
    private function extractInboundMessageId($rawPayload): ?string
    {
        if (! is_array($rawPayload)) {
            return null;
        }

        $id = $rawPayload['message']['mid'] ?? $rawPayload['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Feature #15 — dispatched for a single `statuses[]` entry from the
     * Cloud API's own delivery/read/failed callback. WhatsApp's own status
     * values (sent/delivered/read/failed) map directly onto
     * App\Enums\MessageStatus's values, so no translation table is needed
     * beyond a plain tryFrom(). Reconciliation itself is queued, never
     * applied inline here — see ReconcileOutboundMessageStatus's own
     * docblock for why.
     *
     * @param  array<string, mixed>  $status
     */
    private function dispatchWhatsappStatusReconciliation(LeadSource $source, array $status): void
    {
        $wamid = $status['id'] ?? null;
        $rawStatus = $status['status'] ?? null;
        $messageStatus = is_string($rawStatus) ? MessageStatus::tryFrom($rawStatus) : null;

        if (! is_string($wamid) || $wamid === '' || $messageStatus === null) {
            return;
        }

        $failureReason = $messageStatus === MessageStatus::Failed
            ? ($status['errors'][0]['title'] ?? $status['errors'][0]['message'] ?? 'WhatsApp reported a delivery failure.')
            : null;

        ReconcileOutboundMessageStatus::dispatch(
            tenantId: $source->tenant_id,
            channel: MessageChannel::Whatsapp,
            status: $messageStatus,
            externalMessageId: $wamid,
            failureReason: is_string($failureReason) ? $failureReason : null,
        );
    }

    /**
     * Feature #15 — dispatched for a single `messaging[]` delivery/read
     * entry (Messenger and Instagram share this exact shape). For a
     * delivery/read receipt ABOUT a message this app sent, `recipient.id`
     * is the CUSTOMER's PSID/IGSID (Meta's convention: `sender` is the Page
     * for this specific event type) — the same identifier this app's own
     * Conversation.external_thread_id already uses. `mids` is used
     * opportunistically when present; `watermark` (milliseconds — converted
     * to seconds here, since ReconcileOutboundMessageStatus's own
     * Carbon::createFromTimestamp() expects seconds, matching WhatsApp's
     * own convention) is the primary, always-present mechanism — see
     * ReconcileOutboundMessageStatus's own docblock for why mids alone
     * cannot be relied on.
     *
     * @param  array<string, mixed>  $messaging
     */
    private function dispatchMessengerStatusReconciliation(LeadSource $source, MessageChannel $channel, array $messaging): void
    {
        $customerId = (string) ($messaging['recipient']['id'] ?? '');

        if ($customerId === '') {
            return;
        }

        $isDelivery = isset($messaging['delivery']);
        $event = $messaging['delivery'] ?? $messaging['read'] ?? null;

        if (! is_array($event)) {
            return;
        }

        $watermark = $event['watermark'] ?? null;
        $mids = is_array($event['mids'] ?? null)
            ? array_values(array_filter($event['mids'], 'is_string'))
            : [];

        if ($watermark === null && $mids === []) {
            return;
        }

        ReconcileOutboundMessageStatus::dispatch(
            tenantId: $source->tenant_id,
            channel: $channel,
            status: $isDelivery ? MessageStatus::Delivered : MessageStatus::Read,
            externalMessageIds: $mids,
            leadSourceId: $source->id,
            conversationExternalThreadId: $customerId,
            watermarkTimestamp: is_numeric($watermark) ? (int) ((float) $watermark / 1000) : null,
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

        $accessToken = $source->config['page_access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            Log::error("Facebook lead ad [{$leadgenId}] for tenant [{$tenantCode}]: no Page Access Token configured on the Lead Ads source.");

            return;
        }

        // Locked for the whole dedupe-check + Graph API fetches + create:
        // Meta can redeliver the same leadgen event (retries, duplicate
        // subscriptions) close enough together that two requests could both
        // pass the "already processed" check before either finishes
        // creating the lead — a lead ad submission is immutable once made,
        // so unlike Messenger's self-healing retry-on-follow-up, a
        // duplicate delivery should just be skipped, never re-created.
        try {
            $this->withDedupeLock($source->id, $leadgenId, function () use ($source, $tenantCode, $leadgenId, $value, $accessToken): void {
                $alreadyProcessed = Lead::query()
                    ->where('source_id', $source->id)
                    ->where('source_data->leadgen_id', $leadgenId)
                    ->exists();

                if ($alreadyProcessed) {
                    Log::info("Facebook lead ad [{$leadgenId}] already processed for tenant [{$tenantCode}]; skipping duplicate webhook delivery.");

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
            }, lockSeconds: 30, blockSeconds: 15);
        } catch (LockTimeoutException) {
            // Distinct from both outcomes above (a genuine duplicate, logged
            // and skipped; a genuine processing failure, caught by
            // handleFacebookLeadAd()'s own generic catch) — this delivery
            // lost the dedupe lock to sustained contention and was never
            // even checked, let alone created. Deliberately fails CLOSED
            // (this delivery is dropped) rather than proceeding unprotected
            // the way withWebsiteFormIdempotency() does: source_data
            // ->leadgen_id has no DB-level uniqueness backing it, so running
            // the callback unlocked here could genuinely create two Leads
            // for one leadgen_id, which a dropped-and-retried delivery never
            // can. Meta retries undelivered webhooks for up to 7 days, so
            // this is recovered by the next natural redelivery once the
            // lock is no longer contended — never a manual/automatic retry
            // from here.
            Log::error("Facebook lead ad [{$leadgenId}] for tenant [{$tenantCode}]: dedupe lock timed out under contention; this delivery was dropped, not processed. Expect Meta to retry.");
        }
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
