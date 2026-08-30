<?php

namespace App\Livewire\Platform\Clients;

use App\Enums\LeadSourceType;
use App\Models\LeadSource;
use App\Models\Tenant;
use Flux\Flux;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Lead Sources')]
#[Layout('layouts.admin')]
class LeadSources extends Component
{
    /**
     * Placeholder shown for a sensitive config field's existing value. Never
     * a real secret — if a save submits this literal string back unchanged,
     * the real stored value must be preserved rather than overwritten.
     */
    private const MASKED_VALUE = '••••••••';

    public Tenant $tenant;

    public bool $showModal = false;

    public ?string $editingId = null;

    public string $type = '';

    public string $name = '';

    /** @var array<string, mixed> */
    public array $config = [];

    public function mount(Tenant $tenant): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $tenant);

        $this->tenant = $tenant;
    }

    #[Computed]
    public function leadSources()
    {
        return $this->tenant->leadSources()->latest()->get();
    }

    #[Computed]
    public function typeOptions(): array
    {
        return LeadSourceType::cases();
    }

    #[Computed]
    public function selectedType(): ?LeadSourceType
    {
        return $this->type !== '' ? LeadSourceType::from($this->type) : null;
    }

    public function create(): void
    {
        $this->reset('editingId', 'type', 'name', 'config');
        $this->showModal = true;
    }

    public function edit(string $id): void
    {
        $source = $this->tenant->leadSources()->findOrFail($id);

        $this->editingId = $source->id;
        $this->type = $source->type->value;
        $this->name = $source->name;
        $this->config = $this->maskedConfig($source);
        $this->showModal = true;
    }

    public function save(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $this->tenant);

        $type = LeadSourceType::from($this->type);

        $validated = $this->validate(array_merge(
            [
                'type' => ['required', Rule::enum(LeadSourceType::class)],
                'name' => ['required', 'string', 'max:255'],
            ],
            $type->configValidationRules(isUpdate: $this->editingId !== null),
        ));

        $config = $this->normalizeConfig($type, $validated['config'] ?? []);

        if ($this->editingId) {
            $source = $this->tenant->leadSources()->findOrFail($this->editingId);

            foreach ($type->sensitiveConfigKeys() as $key) {
                if (empty($config[$key]) || $config[$key] === self::MASKED_VALUE) {
                    unset($config[$key]);
                }
            }

            $source->update([
                'name' => $validated['name'],
                'config' => array_merge($source->config ?? [], $config),
            ]);
        } else {
            if ($type === LeadSourceType::WebsiteForm) {
                $config['api_key'] = (string) Str::uuid();
            }

            $this->tenant->leadSources()->create([
                'type' => $type,
                'name' => $validated['name'],
                'config' => $config,
                'is_active' => true,
            ]);
        }

        $this->showModal = false;
        Flux::toast(variant: 'success', text: __('Lead source saved.'));
    }

    public function toggleActive(string $id): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $this->tenant);

        $source = $this->tenant->leadSources()->findOrFail($id);
        $source->update(['is_active' => ! $source->is_active]);
    }

    public function delete(string $id): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $this->tenant);

        $this->tenant->leadSources()->findOrFail($id)->delete();

        Flux::toast(variant: 'success', text: __('Lead source deleted.'));
    }

    public function testConnection(string $id): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $this->tenant);

        $source = $this->tenant->leadSources()->findOrFail($id);

        $result = match ($source->type) {
            LeadSourceType::Email => $this->testEmailConnection($source),
            LeadSourceType::FacebookMessenger => $this->testFacebookConnection($source),
            LeadSourceType::Whatsapp => $this->testWhatsappConnection($source),
            LeadSourceType::Instagram => $this->testInstagramConnection($source),
            LeadSourceType::WebsiteForm => [
                'success' => true,
                'message' => __('There is nothing external to test — POST a sample lead to the webhook URL with the X-Api-Key header to confirm it end-to-end.'),
            ],
        };

        Flux::toast(variant: $result['success'] ? 'success' : 'danger', text: $result['message']);
    }

    /**
     * @return array<string, mixed>
     */
    private function maskedConfig(LeadSource $source): array
    {
        $config = $source->config ?? [];

        foreach ($source->type->sensitiveConfigKeys() as $key) {
            if (! empty($config[$key])) {
                $config[$key] = self::MASKED_VALUE;
            }
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizeConfig(LeadSourceType $type, array $config): array
    {
        if ($type === LeadSourceType::Email) {
            $config['fetch_interval_minutes'] = $config['fetch_interval_minutes'] ?? 15;
        }

        if ($type === LeadSourceType::WebsiteForm && isset($config['allowed_domains'])) {
            $config['allowed_domains'] = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) $config['allowed_domains']),
            )));
        }

        return $config;
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function testEmailConnection(LeadSource $source): array
    {
        if (! extension_loaded('imap')) {
            return ['success' => false, 'message' => __('The IMAP extension is not available on this server.')];
        }

        $config = $source->config ?? [];
        $mailbox = sprintf('{%s:%d/imap/ssl}INBOX', $config['imap_host'] ?? '', $config['imap_port'] ?? 993);

        $connection = @imap_open($mailbox, $config['imap_username'] ?? '', $config['imap_password'] ?? '', OP_HALFOPEN);

        if ($connection === false) {
            $error = imap_last_error();

            // Drain the extension's internal alert/error stacks. Anything left
            // unread here surfaces as a PHP warning at request shutdown (outside
            // this suppressed scope), which Laravel turns into an ErrorException
            // and corrupts the Livewire response.
            imap_errors();
            imap_alerts();

            return ['success' => false, 'message' => __('Unable to connect: :error', ['error' => $error])];
        }

        imap_errors();
        imap_alerts();
        imap_close($connection);

        return ['success' => true, 'message' => __('Connection successful.')];
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function testFacebookConnection(LeadSource $source): array
    {
        $config = $source->config ?? [];
        $pageId = $config['page_id'] ?? null;
        $accessToken = $config['page_access_token'] ?? null;

        if (! $pageId || ! $accessToken) {
            return ['success' => false, 'message' => __('Page ID and Page Access Token must both be set.')];
        }

        try {
            $response = Http::timeout(10)->get("https://graph.facebook.com/{$pageId}", [
                'fields' => 'id,name',
                'access_token' => $accessToken,
            ]);
        } catch (ConnectionException) {
            return ['success' => false, 'message' => __('Could not reach the Facebook Graph API.')];
        }

        if (! $response->successful()) {
            return ['success' => false, 'message' => __('Facebook rejected the request: :error', [
                'error' => $response->json('error.message') ?? __('unknown error'),
            ])];
        }

        $pageName = $response->json('name') ?? $pageId;

        $subscription = $this->subscribeFacebookPageToApp($pageId, $accessToken);

        if (! $subscription['success']) {
            return ['success' => false, 'message' => __('Connected to Facebook Page ":name", but subscribing it to receive messages failed: :error', [
                'name' => $pageName,
                'error' => $subscription['error'],
            ])];
        }

        return ['success' => true, 'message' => __('Connected to Facebook Page ":name" and subscribed it to receive messages.', [
            'name' => $pageName,
        ])];
    }

    /**
     * Subscribe this app to the given Facebook Page's webhook events via
     * `POST /{page-id}/subscribed_apps`. A valid Page Access Token alone does
     * not make Meta deliver events to our webhook URL — the page must also
     * be explicitly subscribed, so this runs every time the connection is
     * tested (the call is idempotent).
     *
     * @return array{success: bool, error: ?string}
     */
    private function subscribeFacebookPageToApp(string $pageId, string $accessToken): array
    {
        try {
            $response = Http::asForm()->timeout(10)->post("https://graph.facebook.com/{$pageId}/subscribed_apps", [
                'subscribed_fields' => 'messages,messaging_postbacks',
                'access_token' => $accessToken,
            ]);
        } catch (ConnectionException) {
            return ['success' => false, 'error' => __('Could not reach the Facebook Graph API.')];
        }

        if (! $response->successful() || $response->json('success') !== true) {
            return ['success' => false, 'error' => $response->json('error.message') ?? __('unknown error')];
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function testWhatsappConnection(LeadSource $source): array
    {
        $config = $source->config ?? [];
        $phoneNumberId = $config['phone_number_id'] ?? null;
        $accessToken = $config['access_token'] ?? null;

        if (! $phoneNumberId || ! $accessToken) {
            return ['success' => false, 'message' => __('Phone Number ID and Access Token must both be set.')];
        }

        try {
            $response = Http::timeout(10)->get("https://graph.facebook.com/{$phoneNumberId}", [
                'fields' => 'verified_name,display_phone_number',
                'access_token' => $accessToken,
            ]);
        } catch (ConnectionException) {
            return ['success' => false, 'message' => __('Could not reach the WhatsApp Cloud API.')];
        }

        if (! $response->successful()) {
            return ['success' => false, 'message' => __('WhatsApp rejected the request: :error', [
                'error' => $response->json('error.message') ?? __('unknown error'),
            ])];
        }

        return ['success' => true, 'message' => __('Connected to WhatsApp number ":name" successfully.', [
            'name' => $response->json('display_phone_number') ?? $response->json('verified_name') ?? $phoneNumberId,
        ])];
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function testInstagramConnection(LeadSource $source): array
    {
        $config = $source->config ?? [];
        $accountId = $config['instagram_account_id'] ?? null;
        $accessToken = $config['access_token'] ?? null;

        if (! $accountId || ! $accessToken) {
            return ['success' => false, 'message' => __('Instagram Business Account ID and Access Token must both be set.')];
        }

        $host = ($config['auth_mode'] ?? 'facebook') === 'instagram'
            ? 'graph.instagram.com'
            : 'graph.facebook.com';

        try {
            $response = Http::timeout(10)->get("https://{$host}/{$accountId}", [
                'fields' => 'username,name',
                'access_token' => $accessToken,
            ]);
        } catch (ConnectionException) {
            return ['success' => false, 'message' => __('Could not reach the Instagram Graph API.')];
        }

        if (! $response->successful()) {
            return ['success' => false, 'message' => __('Instagram rejected the request: :error', [
                'error' => $response->json('error.message') ?? __('unknown error'),
            ])];
        }

        return ['success' => true, 'message' => __('Connected to Instagram account ":name" successfully.', [
            'name' => $response->json('username') ?? $response->json('name') ?? $accountId,
        ])];
    }
}
