<?php

namespace App\Livewire\Platform\Clients;

use App\Enums\LeadSourceType;
use App\Models\Conversation;
use App\Models\Currency;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Tenant;
use App\Models\WebsiteForm;
use Flux\Flux;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    public bool $showDeleteModal = false;

    public ?string $confirmingDeleteId = null;

    public bool $showRemoveStatusModal = false;

    public ?string $confirmingRemoveStatusId = null;

    /**
     * Starts false on every fresh mount so the very first paint can skip
     * the leadSources() query and show a skeleton instead — same technique
     * as App\Livewire\Tenant\Leads\Index::$initialLoadComplete, scaled
     * down: this page's table has no filters, so completeInitialLoad()
     * (fired via wire:init) is the whole $listLoadingTargets. Doesn't gate
     * allLeadStatuses()/allCurrencies() (the pipeline picker/currency
     * checkboxes above the table) — those are the small, cheap, global
     * catalogs, not this tenant's own (potentially larger) source list.
     */
    public bool $initialLoadComplete = false;

    public function mount(Tenant $tenant): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $tenant);

        $this->tenant = $tenant;

        $this->flashSignatureRejections();
    }

    public function completeInitialLoad(): void
    {
        $this->initialLoadComplete = true;
    }

    /**
     * Surfaces any webhook signature rejections recorded since this page
     * was last opened. Meta's request that triggered the rejection has no
     * admin browser session attached — there's nothing to show a live toast
     * to at the moment it happens — so this is the next-best thing: shown
     * once, here, then cleared, rather than a banner that lingers forever.
     */
    private function flashSignatureRejections(): void
    {
        $rejected = $this->tenant->leadSources()->whereNotNull('last_signature_rejection_at')->get();

        foreach ($rejected as $source) {
            Flux::toast(
                variant: 'danger',
                text: __(':name (:type): :reason', [
                    'name' => $source->name,
                    'type' => $source->type->label(),
                    'reason' => $source->last_signature_rejection_reason,
                ]),
            );
        }

        if ($rejected->isNotEmpty()) {
            $this->tenant->leadSources()
                ->whereNotNull('last_signature_rejection_at')
                ->update(['last_signature_rejection_at' => null, 'last_signature_rejection_reason' => null]);
        }
    }

    #[Computed]
    public function leadSources()
    {
        if (! $this->initialLoadComplete) {
            return LeadSource::query()->whereRaw('1 = 0')->get();
        }

        return $this->tenant->leadSources()->latest()->get();
    }

    /**
     * The full global catalog, for the pipeline picker below.
     */
    #[Computed]
    public function allLeadStatuses()
    {
        return LeadStatus::query()->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * This tenant's current pipeline, in order.
     */
    #[Computed]
    public function selectedLeadStatuses()
    {
        return $this->tenant->leadStatuses;
    }

    /**
     * How many of this tenant's leads currently sit in each status —
     * powers the picker's lead-count badges, so an admin can see why a
     * status is locked before they even try to remove it. Reordering never
     * touches leads.status_id at all, so only removal needs a count at all;
     * adding and reordering are always safe regardless of these counts.
     *
     * Deliberately NOT consulted by toggleLeadStatus() itself any more (see
     * that method's own docblock) — its removal guard re-queries this same
     * count fresh, inside its own locked transaction, rather than reusing
     * this Livewire-memoized read, since this computed property's cached
     * value could predate the coordination lock being acquired.
     *
     * @return array<string, int> status id => lead count
     */
    #[Computed]
    public function leadCountsByStatus(): array
    {
        return $this->tenant->leads()
            ->selectRaw('status_id, count(*) as count')
            ->groupBy('status_id')
            ->pluck('count', 'status_id')
            ->all();
    }

    /**
     * Click-to-number pipeline picker: clicking an unselected status
     * appends it at the next position; clicking an already-selected one
     * removes it and renumbers the rest so positions stay contiguous
     * (1..N) — there's never a gap for a later "next click" to land on.
     * Removing a status the tenant still has leads sitting in is refused
     * outright rather than silently orphaning them from the Kanban board
     * (see Lead::nextPipelineStatus() and Index::tenantLeadStatuses(),
     * which both only ever consider the tenant's *current* pipeline) —
     * reassign those leads to another status first.
     *
     * TOCTOU fix: removal is a readers/writer pair against every writer
     * that assigns a Lead's status_id, coordinated via a Tenant-row lock —
     * the same "lock the stable parent row" idiom as every other TOCTOU fix
     * in this module. This method's removal branch takes the exclusive FOR
     * UPDATE lock (App\Models\Tenant::lockForUpdate(), reusing the exact
     * row Lead::nextIdentifier() already locks), while every status-
     * assigning writer (Index::moveLead(), Show::updateStatus(),
     * Index::applyBulkStatusChange(), CreateManualLead,
     * CreateLeadFromImport, ProcessEmailLeadSource,
     * LeadWebhookController::createLead()) takes a shared FOR SHARE lock on
     * the same row before resolving its target/default status. PostgreSQL
     * blocks this FOR UPDATE until every outstanding FOR SHARE commits, and
     * blocks any new FOR SHARE once this one is granted — so whichever side
     * commits first is authoritative, and the loser safely re-resolves
     * against the post-commit pipeline (this removal re-observes a
     * just-committed lead and refuses via the existing "in use" toast
     * below; a writer that loses the race re-reads the pipeline after this
     * commits and finds the status gone, falling through to its own
     * existing "status not found" handling). The addition branch below is
     * deliberately kept entirely outside any lock/transaction — adding a
     * status can never strand a lead, since no lead can already reference a
     * not-yet-added status, so it would only add pointless contention
     * against the removal branch's rare exclusive lock for no safety
     * benefit.
     */
    public function toggleLeadStatus(string $statusId): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $this->tenant);

        $currentIds = $this->tenant->leadStatuses()->orderByPivot('position')->pluck('lead_statuses.id')->all();

        if (! in_array($statusId, $currentIds, true)) {
            $newIds = [...$currentIds, $statusId];

            $syncData = [];

            foreach ($newIds as $index => $id) {
                $syncData[$id] = ['position' => $index + 1];
            }

            $this->tenant->leadStatuses()->sync($syncData);

            unset($this->selectedLeadStatuses);

            return;
        }

        DB::transaction(function () use ($statusId): void {
            $tenant = Tenant::whereKey($this->tenant->id)->lockForUpdate()->firstOrFail();

            // Re-read fresh under the lock — deliberately not the
            // unlocked $currentIds captured above, which could predate
            // lock acquisition (see this method's own docblock).
            $currentIds = $tenant->leadStatuses()->orderByPivot('position')->pluck('lead_statuses.id')->all();

            if (! in_array($statusId, $currentIds, true)) {
                // Already removed by a concurrent call (e.g. a stale
                // double-click) since the unlocked check above — nothing
                // left to do.
                return;
            }

            if (count($currentIds) <= 1) {
                Flux::toast(variant: 'danger', text: __('A client must have at least one pipeline status.'));

                return;
            }

            // Freshly queried under the lock above, deliberately not
            // $this->leadCountsByStatus (see that computed property's
            // own docblock) — that read could predate lock acquisition.
            $leadCount = (int) $tenant->leads()->where('status_id', $statusId)->count();

            if ($leadCount > 0) {
                Flux::toast(variant: 'danger', text: trans_choice(
                    ':count lead is currently in this status — reassign it before removing this status from the pipeline.|:count leads are currently in this status — reassign them before removing this status from the pipeline.',
                    $leadCount,
                    ['count' => $leadCount],
                ));

                return;
            }

            $newIds = array_values(array_diff($currentIds, [$statusId]));

            $syncData = [];

            foreach ($newIds as $index => $id) {
                $syncData[$id] = ['position' => $index + 1];
            }

            $tenant->leadStatuses()->sync($syncData);
        });

        unset($this->selectedLeadStatuses);
    }

    /**
     * The full global catalog, for the currency picker below.
     */
    #[Computed]
    public function allCurrencies()
    {
        return Currency::query()->orderBy('code')->get();
    }

    /**
     * This tenant's currently enabled currencies, for Est. Budget / Deal
     * Value on their leads.
     */
    #[Computed]
    public function selectedCurrencies()
    {
        return $this->tenant->currencies()->orderBy('code')->get();
    }

    /**
     * Simple checkbox toggle — unlike toggleLeadStatus(), there's no
     * ordering to preserve and no "leads currently use this" block, only
     * the one invariant the tenant must always satisfy: at least one
     * currency enabled, since LeadSources::create()/save() below refuse to
     * let a tenant with zero create a new Lead Source at all, and every
     * lead's Est. Budget / Deal Value dropdown needs at least one option.
     */
    public function toggleCurrency(string $currencyId): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $this->tenant);

        $currentIds = $this->tenant->currencies()->pluck('currencies.id')->all();
        $alreadySelected = in_array($currencyId, $currentIds, true);

        if ($alreadySelected && count($currentIds) <= 1) {
            Flux::toast(variant: 'danger', text: __('A client must have at least one enabled currency.'));

            return;
        }

        if ($alreadySelected) {
            $this->tenant->currencies()->detach($currencyId);
        } else {
            $this->tenant->currencies()->attach($currencyId);
        }

        unset($this->selectedCurrencies);
    }

    /**
     * Excludes LeadSourceType::Manual and ::CsvImport: neither is a
     * Platform-Admin-configured external channel - Manual is the single
     * per-tenant source manually keyed-in leads are attributed to,
     * CsvImport the equivalent for Feature #7 CSV imports, both
     * provisioned automatically the first time a tenant user uses them.
     */
    #[Computed]
    public function typeOptions(): array
    {
        return array_filter(
            LeadSourceType::cases(),
            fn (LeadSourceType $type): bool => ! in_array($type, [LeadSourceType::Manual, LeadSourceType::CsvImport], true),
        );
    }

    #[Computed]
    public function selectedType(): ?LeadSourceType
    {
        return $this->type !== '' ? LeadSourceType::from($this->type) : null;
    }

    /**
     * A tenant with zero enabled currencies can't create a new Lead Source
     * at all — Est. Budget / Deal Value on any lead it produces would have
     * no currency to default to. Only gates *creating*: editing/testing/
     * deleting an existing source is unaffected, since those don't depend
     * on the currency picker.
     */
    public function create(): void
    {
        if ($this->tenant->currencies()->doesntExist()) {
            Flux::toast(variant: 'danger', text: __('Enable at least one currency for this client before creating a Lead Source.'));

            return;
        }

        $this->reset('editingId', 'type', 'name', 'config');
        $this->showModal = true;
    }

    /**
     * A `select`-type config field is rendered via <x-dropdown-select>,
     * which binds to Livewire through Alpine's $wire.entangle() rather than
     * a native wire:model on an <input>/<select>. Unlike native wire:model,
     * entangle() throws "Livewire property cannot be found on component"
     * and never syncs if the target path doesn't already exist server-side
     * — so every config key for the current type must be pre-seeded (even
     * as null) before the field renders, or the dropdown-select silently
     * stays local-only and any field depending on it via `depends_on` never
     * sees a value and stays disabled forever.
     */
    public function updatedType(): void
    {
        $type = LeadSourceType::tryFrom($this->type);

        $this->config = $type
            ? array_fill_keys(array_column($type->configFields(), 'key'), null)
            : [];
    }

    public function edit(string $id): void
    {
        $source = $this->tenant->leadSources()->findOrFail($id);

        $this->editingId = $source->id;
        $this->type = $source->type->value;
        $this->name = $source->name;
        $this->config = array_merge(
            array_fill_keys(array_column($source->type->configFields(), 'key'), null),
            $this->maskedConfig($source)
        );
        $this->showModal = true;
    }

    public function save(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $this->tenant);

        // Re-checked here, not just in create(): the modal could have been
        // opened before the tenant's last currency was removed elsewhere
        // in the same session, and this is what actually persists the row.
        if ($this->editingId === null && $this->tenant->currencies()->doesntExist()) {
            Flux::toast(variant: 'danger', text: __('Enable at least one currency for this client before creating a Lead Source.'));

            return;
        }

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

    public function confirmDelete(string $id): void
    {
        $this->confirmingDeleteId = $id;
        $this->showDeleteModal = true;
    }

    public function deleteConfirmed(): void
    {
        if ($this->confirmingDeleteId) {
            $this->delete($this->confirmingDeleteId);
        }

        $this->showDeleteModal = false;
        $this->confirmingDeleteId = null;
    }

    /**
     * Unlike the tenant-side Facebook-Lead-Ads-only deleteSource() (which
     * only ever reaches a source with no leads or costs — see
     * Tenant\Leads\Index::deleteSource()), this Platform Admin path can
     * reach any source of any type, so it must guard every direct
     * dependent itself: leads/lead_imports cascade on delete (a source
     * with either would otherwise silently take its whole history down
     * with it), while lead_source_costs/website_forms/conversations
     * restrict on delete (a source with any of those would otherwise fail
     * with an uncaught QueryException instead of a clear message).
     * Conversations are checked first since that dependency has no
     * existing way to be cleared (unlike costs/forms, which a tenant can
     * delete individually) — a source with conversation history is
     * permanently, not just currently, blocked from deletion.
     *
     * Locks the LeadSource row before checking so a webhook/import cannot
     * create a new dependency between the check and the delete — the
     * same "lock the stable parent row" idiom already used by
     * LeadSourceCosts\Index::save() and Lead::nextIdentifier().
     */
    public function delete(string $id): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $this->tenant);

        DB::transaction(function () use ($id): void {
            $source = $this->tenant->leadSources()->lockForUpdate()->findOrFail($id);

            if (Conversation::where('lead_source_id', $source->id)->exists()) {
                Flux::toast(variant: 'danger', text: __('This source has conversation history and can no longer be deleted.'));

                return;
            }

            if ($source->leads()->exists()) {
                Flux::toast(variant: 'danger', text: __('This source has leads and can no longer be deleted.'));

                return;
            }

            if (LeadImport::where('source_id', $source->id)->exists()) {
                Flux::toast(variant: 'danger', text: __('This source has import history and can no longer be deleted.'));

                return;
            }

            if ($source->costs()->exists()) {
                Flux::toast(variant: 'danger', text: __('This source has recorded cost history and can no longer be deleted.'));

                return;
            }

            if (WebsiteForm::where('lead_source_id', $source->id)->exists()) {
                Flux::toast(variant: 'danger', text: __('This source has website forms configured and can no longer be deleted.'));

                return;
            }

            $source->delete();

            Flux::toast(variant: 'success', text: __('Lead source deleted.'));
        });
    }

    /**
     * Only the "remove from pipeline" direction of toggleLeadStatus() needs
     * confirming — adding a status is a one-click, freely-reversible action.
     * The Blade side only ever calls this from the "already selected"
     * button, never the "add" one, so this always means removal.
     */
    public function confirmRemoveStatus(string $statusId): void
    {
        $this->confirmingRemoveStatusId = $statusId;
        $this->showRemoveStatusModal = true;
    }

    public function removeStatusConfirmed(): void
    {
        if ($this->confirmingRemoveStatusId) {
            $this->toggleLeadStatus($this->confirmingRemoveStatusId);
        }

        $this->showRemoveStatusModal = false;
        $this->confirmingRemoveStatusId = null;
    }

    public function testConnection(string $id): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $this->tenant);

        $source = $this->tenant->leadSources()->findOrFail($id);

        $result = match ($source->type) {
            LeadSourceType::Email => $this->testEmailConnection($source),
            LeadSourceType::FacebookMessenger => $this->testFacebookConnection($source),
            LeadSourceType::FacebookLeadAds => $this->testFacebookLeadAdsConnection($source),
            LeadSourceType::Whatsapp => $this->testWhatsappConnection($source),
            LeadSourceType::Instagram => $this->testInstagramConnection($source),
            LeadSourceType::LinkedInLeadGen => $this->testLinkedInConnection($source),
            LeadSourceType::WebsiteForm => [
                'success' => true,
                'message' => __('There is nothing external to test — POST a sample lead to the webhook URL with the X-Api-Key header to confirm it end-to-end.'),
            ],
            LeadSourceType::Manual => [
                'success' => true,
                'message' => __('Manual entry has nothing external to test.'),
            ],
            LeadSourceType::CsvImport => [
                'success' => true,
                'message' => __('CSV Import has nothing external to test.'),
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
        if ($type === LeadSourceType::Email || $type === LeadSourceType::LinkedInLeadGen) {
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

        $subscription = $this->subscribeFacebookPageToApp($pageId, $accessToken, 'messages,messaging_postbacks');

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
     * Facebook Lead Ads (Instant Forms) delivers `leadgen` change events to
     * the same Page-level webhook subscription as Messenger — this only
     * differs from testFacebookConnection() in which field it subscribes.
     * A tenant can configure Lead Ads without Messenger (and vice versa),
     * so each keeps its own Page ID/token entry rather than sharing one.
     *
     * @return array{success: bool, message: string}
     */
    private function testFacebookLeadAdsConnection(LeadSource $source): array
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

        $subscription = $this->subscribeFacebookPageToApp($pageId, $accessToken, 'leadgen');

        if (! $subscription['success']) {
            return ['success' => false, 'message' => __('Connected to Facebook Page ":name", but subscribing it to receive lead ads failed: :error', [
                'name' => $pageName,
                'error' => $subscription['error'],
            ])];
        }

        return ['success' => true, 'message' => __('Connected to Facebook Page ":name" and subscribed it to receive lead ad submissions.', [
            'name' => $pageName,
        ])];
    }

    /**
     * Subscribe this app to the given Facebook Page's webhook events via
     * `POST /{page-id}/subscribed_apps`. A valid Page Access Token alone does
     * not make Meta deliver events to our webhook URL — the page must also
     * be explicitly subscribed, so this runs every time the connection is
     * tested (the call is idempotent). Per Meta's docs this call is additive
     * — subscribing `leadgen` doesn't drop an existing `messages` subscription
     * on the same page — but that's Meta's documented behavior, not something
     * this app can independently verify from here.
     *
     * @return array{success: bool, error: ?string}
     */
    private function subscribeFacebookPageToApp(string $pageId, string $accessToken, string $subscribedFields): array
    {
        try {
            $response = Http::asForm()->timeout(10)->post("https://graph.facebook.com/{$pageId}/subscribed_apps", [
                'subscribed_fields' => $subscribedFields,
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

    /**
     * Unlike every other type's "test connection", a LinkedIn source's
     * access_token is never typed in by an admin — it only arrives via the
     * OAuth callback (Phase 2, not yet implemented). Until a source has
     * been through that flow, "no token yet" is an expected state, not a
     * misconfiguration, so it gets its own message distinct from "not
     * configured at all".
     *
     * @return array{success: bool, message: string}
     */
    private function testLinkedInConnection(LeadSource $source): array
    {
        $config = $source->config ?? [];
        $organizationUrn = $config['organization_urn'] ?? null;
        $accessToken = $config['access_token'] ?? null;

        if (! $organizationUrn) {
            return ['success' => false, 'message' => __('Organization / Sponsored Account URN must be set.')];
        }

        if (! $accessToken) {
            return ['success' => false, 'message' => __('This source has not been connected to LinkedIn yet — use "Connect with LinkedIn" to authorize it.')];
        }

        $apiVersion = config('services.linkedin.api_version');

        if (! $apiVersion) {
            return ['success' => false, 'message' => __('LINKEDIN_API_VERSION is not configured — set it before testing a LinkedIn connection.')];
        }

        try {
            $response = Http::withToken($accessToken)
                ->withHeaders([
                    'Linkedin-Version' => $apiVersion,
                    'X-Restli-Protocol-Version' => '2.0.0',
                ])
                ->timeout(10)
                ->get('https://api.linkedin.com/rest/leadForms', [
                    'q' => 'owner',
                    'owner' => "({$this->linkedInOwnerKey($organizationUrn)}:{$organizationUrn})",
                    'count' => 1,
                ]);
        } catch (ConnectionException) {
            return ['success' => false, 'message' => __('Could not reach the LinkedIn API.')];
        }

        if (! $response->successful()) {
            return ['success' => false, 'message' => __('LinkedIn rejected the request: :error', [
                'error' => $response->json('message') ?? __('unknown error'),
            ])];
        }

        return ['success' => true, 'message' => __('Connected to LinkedIn successfully.')];
    }

    /**
     * LinkedIn's `owner` union type is keyed by which kind of URN it is —
     * `organization` for a Company Page or `sponsoredAccount` for an ad
     * account. Both are valid values for `organization_urn`, so this just
     * reads the URN's own resource segment rather than requiring a second
     * config field to say which one it is.
     */
    private function linkedInOwnerKey(string $organizationUrn): string
    {
        return str_contains($organizationUrn, ':sponsoredAccount:') ? 'sponsoredAccount' : 'organization';
    }
}
