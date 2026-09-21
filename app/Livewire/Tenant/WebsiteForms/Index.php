<?php

namespace App\Livewire\Tenant\WebsiteForms;

use App\Enums\ConsentType;
use App\Enums\LeadSourceType;
use App\Enums\WebsiteFormFieldType;
use App\Enums\WebsiteFormFixedField;
use App\Models\LeadCustomFieldDefinition;
use App\Models\LeadSource;
use App\Models\WebsiteForm;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Feature #13 — tenant self-service CRUD for the Website Form Builder
 * catalog. Client-Admin-only (see App\Policies\WebsiteFormPolicy), same
 * "reuse the existing 'update' permission + isClientAdmin()" pattern as
 * LeadTags/LeadScoring/LeadCustomFields/LeadSourceCosts.
 *
 * A form's field configuration (which fixed fields + which active custom
 * fields it accepts) is edited inline in the same create/edit modal as the
 * form's name/active flag, then persisted by deleting and recreating every
 * WebsiteFormField row on save() — safe because those rows are pure
 * configuration (which fields THIS form exposes), never submitted data, so
 * there is nothing to lose by replacing the set wholesale each save.
 */
#[Title('Website Forms')]
class Index extends Component
{
    public bool $showModal = false;

    public ?string $editingId = null;

    public string $name = '';

    public bool $isActive = true;

    /** @var array<int, string> */
    public array $fixedFields = [];

    /** @var array<int, string> */
    public array $customFieldIds = [];

    /**
     * Feature #14 — which App\Enums\ConsentType values this form captures.
     * A second, independent checkbox group (not per-type sub-properties)
     * marks which of those are required — mirrors $fixedFields' own
     * flat-checkbox-array convention.
     *
     * @var array<int, string>
     */
    public array $consentTypes = [];

    /** @var array<int, string> */
    public array $consentRequired = [];

    public string $privacyPolicyVersion = '';

    public string $privacyPolicyUrl = '';

    public string $marketingPolicyVersion = '';

    public string $marketingPolicyUrl = '';

    public bool $showTokenModal = false;

    public string $revealedToken = '';

    public ?string $revealedFormName = null;

    public bool $showRegenerateModal = false;

    public ?string $regeneratingId = null;

    public bool $showDeleteModal = false;

    public ?string $confirmingDeleteId = null;

    public bool $showIntegrationModal = false;

    public ?string $viewingId = null;

    public function mount(): void
    {
        // Whole page Client-Admin-gated, same precedent as
        // App\Livewire\Tenant\LeadCustomFields\Index::mount() — a form that
        // can't be created here can't be usefully viewed either, since this
        // page has no separate read-only mode.
        Gate::authorize('viewAny', WebsiteForm::class);
    }

    #[Computed]
    public function forms()
    {
        return WebsiteForm::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->withCount('fields')
            ->orderBy('created_at')
            ->get();
    }

    #[Computed]
    public function activeCustomFieldDefinitions()
    {
        return LeadCustomFieldDefinition::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @return array<string, string> fixed field value => label
     */
    public function fixedFieldOptions(): array
    {
        return collect(WebsiteFormFixedField::cases())
            ->mapWithKeys(fn (WebsiteFormFixedField $f): array => [$f->value => $f->label()])
            ->all();
    }

    public function create(): void
    {
        Gate::authorize('create', [WebsiteForm::class, Auth::user()->tenant]);

        $this->reset(
            'editingId', 'name', 'fixedFields', 'customFieldIds',
            'consentTypes', 'consentRequired', 'privacyPolicyVersion', 'privacyPolicyUrl',
            'marketingPolicyVersion', 'marketingPolicyUrl',
        );
        $this->isActive = true;
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function edit(string $id): void
    {
        $form = WebsiteForm::where('tenant_id', Auth::user()->tenant_id)
            ->with('fields')
            ->findOrFail($id);

        Gate::authorize('update', $form);

        $this->editingId = $form->id;
        $this->name = $form->name;
        $this->isActive = $form->is_active;
        $this->fixedFields = $form->fields
            ->where('field_type', WebsiteFormFieldType::Fixed)
            ->pluck('fixed_field')
            ->all();
        $this->customFieldIds = $form->fields
            ->where('field_type', WebsiteFormFieldType::Custom)
            ->pluck('lead_custom_field_definition_id')
            ->all();

        $consentFields = $form->fields->where('field_type', WebsiteFormFieldType::Consent);
        $this->consentTypes = $consentFields->pluck('consent_type.value')->all();
        $this->consentRequired = $consentFields->where('is_required', true)->pluck('consent_type.value')->all();

        $privacyField = $consentFields->firstWhere('consent_type', ConsentType::Privacy);
        $this->privacyPolicyVersion = $privacyField?->policy_version ?? '';
        $this->privacyPolicyUrl = $privacyField?->policy_url ?? '';

        $marketingField = $consentFields->firstWhere('consent_type', ConsentType::Marketing);
        $this->marketingPolicyVersion = $marketingField?->policy_version ?? '';
        $this->marketingPolicyUrl = $marketingField?->policy_url ?? '';

        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function save(): void
    {
        $tenant = Auth::user()->tenant;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'isActive' => ['boolean'],
            'fixedFields' => ['array'],
            'fixedFields.*' => ['string', 'in:'.implode(',', WebsiteFormFixedField::values())],
            'customFieldIds' => ['array'],
            'customFieldIds.*' => ['string'],
            'consentTypes' => ['array'],
            'consentTypes.*' => ['string', 'in:'.implode(',', ConsentType::values())],
            'consentRequired' => ['array'],
            'consentRequired.*' => ['string', 'in:'.implode(',', ConsentType::values())],
            'privacyPolicyVersion' => ['nullable', 'string', 'max:255'],
            'privacyPolicyUrl' => ['nullable', 'string', 'max:255'],
            'marketingPolicyVersion' => ['nullable', 'string', 'max:255'],
            'marketingPolicyUrl' => ['nullable', 'string', 'max:255'],
        ]);

        // Never trust that a submitted custom field id is still one of this
        // tenant's own ACTIVE definitions — re-derive the accepted set from
        // the database instead of the request.
        $validCustomFieldIds = $this->activeCustomFieldDefinitions
            ->pluck('id')
            ->intersect($validated['customFieldIds'])
            ->values()
            ->all();

        DB::transaction(function () use ($tenant, $validated, $validCustomFieldIds): void {
            if ($this->editingId) {
                $form = WebsiteForm::where('tenant_id', $tenant->id)->findOrFail($this->editingId);
                Gate::authorize('update', $form);

                $form->update([
                    'name' => $validated['name'],
                    'is_active' => $this->isActive,
                ]);
            } else {
                Gate::authorize('create', [WebsiteForm::class, $tenant]);

                // Every WebsiteForm for a tenant shares ONE "Website Form"
                // LeadSource — lazily created here the first time this
                // tenant creates a form, reusing the legacy webhook's
                // source row if one already exists (see WebsiteForm::
                // leadSource()'s docblock).
                $source = LeadSource::query()->firstOrCreate(
                    ['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm],
                    ['name' => 'Website Form', 'is_active' => true],
                );

                $plaintextToken = Str::random(40);

                $form = WebsiteForm::create([
                    'tenant_id' => $tenant->id,
                    'lead_source_id' => $source->id,
                    'name' => $validated['name'],
                    'token_hash' => hash('sha256', $plaintextToken),
                    'is_active' => $this->isActive,
                ]);

                $this->revealedToken = $plaintextToken;
                $this->revealedFormName = $form->name;
            }

            $this->syncFields($form, $validated['fixedFields'], $validCustomFieldIds, $validated['consentTypes'], $validated['consentRequired']);
        });

        $isNew = ! $this->editingId;
        $this->showModal = false;
        unset($this->forms);
        Flux::toast(variant: 'success', text: __('Website form saved.'));

        if ($isNew) {
            $this->showTokenModal = true;
        }
    }

    /**
     * Replaces a form's entire field configuration wholesale — these rows
     * carry no submitted data (see class docblock), so delete-then-recreate
     * is simpler and just as safe as a diff/upsert.
     *
     * @param  array<int, string>  $fixedFields
     * @param  array<int, string>  $customFieldIds
     * @param  array<int, string>  $consentTypes
     * @param  array<int, string>  $consentRequired
     */
    private function syncFields(WebsiteForm $form, array $fixedFields, array $customFieldIds, array $consentTypes = [], array $consentRequired = []): void
    {
        $form->fields()->delete();

        $sortOrder = 0;

        foreach ($fixedFields as $fixedField) {
            $form->fields()->create([
                'tenant_id' => $form->tenant_id,
                'field_type' => WebsiteFormFieldType::Fixed,
                'fixed_field' => $fixedField,
                'sort_order' => $sortOrder++,
            ]);
        }

        foreach ($customFieldIds as $definitionId) {
            $form->fields()->create([
                'tenant_id' => $form->tenant_id,
                'field_type' => WebsiteFormFieldType::Custom,
                'lead_custom_field_definition_id' => $definitionId,
                'sort_order' => $sortOrder++,
            ]);
        }

        // Feature #14 — policy metadata is per-type, read directly off this
        // component's own scalar properties (only two types exist, so a
        // generic array structure would be more indirection for no benefit
        // — see the class properties' own docblock).
        foreach ($consentTypes as $rawType) {
            $type = ConsentType::from($rawType);

            $form->fields()->create([
                'tenant_id' => $form->tenant_id,
                'field_type' => WebsiteFormFieldType::Consent,
                'consent_type' => $type,
                'is_required' => in_array($rawType, $consentRequired, true),
                'policy_version' => $type === ConsentType::Privacy ? ($this->privacyPolicyVersion ?: null) : ($this->marketingPolicyVersion ?: null),
                'policy_url' => $type === ConsentType::Privacy ? ($this->privacyPolicyUrl ?: null) : ($this->marketingPolicyUrl ?: null),
                'sort_order' => $sortOrder++,
            ]);
        }
    }

    public function toggleActive(string $id): void
    {
        $form = WebsiteForm::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('update', $form);

        $form->update(['is_active' => ! $form->is_active]);

        unset($this->forms);
        Flux::toast(variant: 'success', text: $form->is_active ? __('Form activated.') : __('Form deactivated.'));
    }

    public function confirmRegenerate(string $id): void
    {
        $this->regeneratingId = $id;
        $this->showRegenerateModal = true;
    }

    /**
     * The old token becomes immediately invalid — a fresh SHA-256 hash
     * simply replaces the old one, there's no grace-period overlap.
     */
    public function regenerateConfirmed(): void
    {
        if (! $this->regeneratingId) {
            return;
        }

        $form = WebsiteForm::where('tenant_id', Auth::user()->tenant_id)->findOrFail($this->regeneratingId);

        Gate::authorize('update', $form);

        $plaintextToken = Str::random(40);

        $form->update(['token_hash' => hash('sha256', $plaintextToken)]);

        $this->showRegenerateModal = false;
        $this->regeneratingId = null;

        $this->revealedToken = $plaintextToken;
        $this->revealedFormName = $form->name;
        $this->showTokenModal = true;

        unset($this->forms);
        Flux::toast(variant: 'success', text: __('Token regenerated. The old token no longer works.'));
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
     * Unconditionally allowed — a WebsiteForm has no hard FK from Lead
     * (attribution is a source_data snapshot only, see the model docblock),
     * so deleting it never touches any existing Lead.
     */
    public function delete(string $id): void
    {
        $form = WebsiteForm::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('delete', $form);

        $form->delete();

        unset($this->forms);
        Flux::toast(variant: 'success', text: __('Website form deleted.'));
    }

    public function viewIntegrationDetails(string $id): void
    {
        $this->viewingId = $id;
        $this->showIntegrationModal = true;
    }

    #[Computed]
    public function viewingForm(): ?WebsiteForm
    {
        if (! $this->viewingId) {
            return null;
        }

        return WebsiteForm::where('tenant_id', Auth::user()->tenant_id)
            ->with('fields.customFieldDefinition')
            ->find($this->viewingId);
    }

    /**
     * route() treats a literal "{token}" value as an unfilled parameter and
     * throws, so the URL is generated with a sentinel and the display value
     * (the "{token}" placeholder by default) is swapped in afterwards.
     */
    public function submissionUrl(WebsiteForm $form, string $token = '{token}'): string
    {
        $sentinel = 'TOKENPLACEHOLDER';

        return str_replace(
            $sentinel,
            $token,
            route('api.webhook.website-form.submit', [Auth::user()->tenant->tenant_code, $sentinel]),
        );
    }
}
