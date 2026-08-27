<?php

namespace App\Http\Controllers\Api;

use App\Enums\LeadSourceType;
use App\Enums\LeadStatus;
use App\Enums\TenantStatus;
use App\Events\LeadReceived;
use App\Events\LeadUpdated;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Tenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class LeadWebhookController extends Controller
{
    /**
     * Verify a Facebook Messenger webhook subscription challenge.
     */
    public function verifyFacebook(Request $request, string $tenant_code): Response
    {
        return $this->verifyHubChallenge($request, $this->resolveSource($tenant_code, LeadSourceType::FacebookMessenger));
    }

    /**
     * Handle an incoming Facebook Messenger message webhook.
     */
    public function facebookMessenger(Request $request, string $tenant_code): JsonResponse
    {
        $source = $this->resolveSource($tenant_code, LeadSourceType::FacebookMessenger);

        foreach ($request->array('entry') as $entry) {
            foreach (($entry['messaging'] ?? []) as $messaging) {
                $senderId = (string) ($messaging['sender']['id'] ?? 'unknown');
                $messageText = $messaging['message']['text'] ?? null;

                $existingLead = Lead::query()
                    ->where('source_id', $source->id)
                    ->where('source_data->facebook_user_id', $senderId)
                    ->first();

                if ($existingLead instanceof Lead) {
                    $this->appendFacebookMessage($existingLead, $messageText, $messaging);

                    $source->update(['last_synced_at' => now()]);

                    continue;
                }

                $profile = $this->fetchFacebookProfile($source, $senderId);

                $this->createLead($source, [
                    'name' => $profile['name'] ?? "Facebook User {$senderId}",
                    'message' => $messageText,
                    'source_data' => [
                        'facebook_user_id' => $senderId,
                        'facebook_profile_pic' => $profile['profile_pic'],
                        'messages' => [$messaging],
                    ],
                ]);
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
     */
    public function whatsapp(Request $request, string $tenant_code): JsonResponse
    {
        $source = $this->resolveSource($tenant_code, LeadSourceType::Whatsapp);

        foreach ($request->array('entry') as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                foreach (($change['value']['messages'] ?? []) as $message) {
                    $this->createLead($source, [
                        'name' => 'WhatsApp User '.($message['from'] ?? 'unknown'),
                        'phone' => $message['from'] ?? null,
                        'message' => $message['text']['body'] ?? null,
                        'source_data' => $message,
                    ]);
                }
            }
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
     */
    private function appendFacebookMessage(Lead $lead, ?string $messageText, $messaging): void
    {
        if ($messageText !== null && $messageText !== '') {
            $lead->message = trim(($lead->message ?? '')."\n".$messageText);
        }

        $sourceData = $lead->source_data ?? [];
        $sourceData['messages'][] = $messaging;
        $lead->source_data = $sourceData;

        $lead->save();

        event(new LeadUpdated($lead));
    }

    /**
     * Look up a Facebook user's display name and profile picture via the
     * Graph API using the lead source's page access token.
     *
     * @return array{name: ?string, profile_pic: ?string}
     */
    private function fetchFacebookProfile(LeadSource $source, string $psid): array
    {
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
        } catch (ConnectionException) {
            return $empty;
        }

        if (! $response->successful()) {
            return $empty;
        }

        $name = $response->json('name');

        if (! is_string($name) || $name === '') {
            $fullName = trim(($response->json('first_name') ?? '').' '.($response->json('last_name') ?? ''));
            $name = $fullName !== '' ? $fullName : null;
        }

        $profilePic = $response->json('profile_pic');

        return [
            'name' => $name,
            'profile_pic' => is_string($profilePic) && $profilePic !== '' ? $profilePic : null,
        ];
    }
}
