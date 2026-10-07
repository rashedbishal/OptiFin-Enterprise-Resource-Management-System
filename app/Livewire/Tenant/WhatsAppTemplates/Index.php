<?php

namespace App\Livewire\Tenant\WhatsAppTemplates;

use App\Enums\LeadSourceType;
use App\Models\LeadSource;
use App\Models\WhatsAppMessageTemplate;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Tenant self-service catalog of WhatsApp message templates already
 * created and approved directly in Meta's own WhatsApp Manager — this app
 * never creates/submits a template to Meta (see WhatsAppMessageTemplate's
 * own docblock for why). Mirrors LeadTags\Index/LeadSourceCosts\Index's
 * create/edit/save/confirmDelete/deleteConfirmed/delete shape,
 * Client-Admin-only.
 *
 * Redirects to the hub the same way CallingSettings\Index does when the
 * tenant has no WhatsApp lead source — this catalog is meaningless without
 * one (see Dashboard::hasWhatsAppSource(), the same condition that gates
 * this page's own tile on the hub).
 */
#[Title('WhatsApp Templates')]
class Index extends Component
{
    public bool $showModal = false;

    public ?string $editingId = null;

    public string $sourceId = '';

    public string $name = '';

    public string $language = 'en_US';

    public string $variableCount = '0';

    public bool $showDeleteModal = false;

    public ?string $confirmingDeleteId = null;

    public function mount(): void
    {
        Gate::authorize('viewAny', WhatsAppMessageTemplate::class);

        if ($this->eligibleSources->isEmpty()) {
            $this->redirect(route('lead-management.index'), navigate: true);
        }
    }

    /**
     * This tenant's WhatsApp lead sources — the only type a template can
     * be registered against. A tenant with none has nothing to register a
     * template for, and never sees this page (see mount() above).
     *
     * @return Collection<int, LeadSource>
     */
    #[Computed]
    public function eligibleSources(): Collection
    {
        return LeadSource::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->where('type', LeadSourceType::Whatsapp)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, WhatsAppMessageTemplate>
     */
    #[Computed]
    public function templates(): Collection
    {
        return WhatsAppMessageTemplate::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->with('leadSource')
            ->orderByDesc('created_at')
            ->get();
    }

    public function create(): void
    {
        Gate::authorize('create', [WhatsAppMessageTemplate::class, Auth::user()->tenant]);

        $this->reset('editingId', 'name');
        $this->language = 'en_US';
        $this->variableCount = '0';
        $this->sourceId = (string) $this->eligibleSources->first()?->id;
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function edit(string $id): void
    {
        $template = WhatsAppMessageTemplate::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('update', $template);

        $this->editingId = $template->id;
        $this->sourceId = $template->lead_source_id;
        $this->name = $template->name;
        $this->language = $template->language;
        $this->variableCount = (string) $template->variable_count;
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function save(): void
    {
        $tenant = Auth::user()->tenant;

        $validated = $this->validate([
            'sourceId' => [
                'required', 'string',
                Rule::exists('lead_sources', 'id')->where('tenant_id', $tenant->id)->where('type', LeadSourceType::Whatsapp->value),
            ],
            // Meta's own naming rule for a template: lowercase letters,
            // numbers, and underscores only.
            'name' => ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'language' => ['required', 'string', 'max:35'],
            'variableCount' => ['required', 'integer', 'min:0', 'max:10'],
        ], [
            'name.regex' => __("Must match the exact template name in Meta's WhatsApp Manager — lowercase letters, numbers, and underscores only."),
        ]);

        $duplicate = WhatsAppMessageTemplate::query()
            ->where('lead_source_id', $validated['sourceId'])
            ->where('name', $validated['name'])
            ->where('language', $validated['language'])
            ->when($this->editingId, fn ($query) => $query->whereKeyNot($this->editingId))
            ->exists();

        if ($duplicate) {
            $this->addError('name', __('This source already has a template with this exact name and language.'));

            return;
        }

        // Not atomic with the duplicate check above — same accepted
        // pattern as LeadTags\Index::save()'s identical slugTaken check;
        // the real unique(lead_source_id, name, language) constraint (see
        // the migration) is what actually prevents a genuine race, this
        // catch just turns it into the same graceful validation error.
        try {
            if ($this->editingId) {
                $template = WhatsAppMessageTemplate::where('tenant_id', $tenant->id)->findOrFail($this->editingId);
                Gate::authorize('update', $template);

                $template->update([
                    'lead_source_id' => $validated['sourceId'],
                    'name' => $validated['name'],
                    'language' => $validated['language'],
                    'variable_count' => $validated['variableCount'],
                ]);
            } else {
                Gate::authorize('create', [WhatsAppMessageTemplate::class, $tenant]);

                WhatsAppMessageTemplate::create([
                    'tenant_id' => $tenant->id,
                    'lead_source_id' => $validated['sourceId'],
                    'name' => $validated['name'],
                    'language' => $validated['language'],
                    'variable_count' => $validated['variableCount'],
                    'is_active' => true,
                ]);
            }
        } catch (UniqueConstraintViolationException) {
            $this->addError('name', __('This source already has a template with this exact name and language.'));

            return;
        }

        $this->showModal = false;
        unset($this->templates);
        Flux::toast(variant: 'success', text: __('Template saved.'));
    }

    /**
     * A soft on/off switch — deactivating keeps the row (and any Message
     * rows that already snapshotted it) rather than deleting, same
     * reasoning as the column's own migration docblock.
     */
    public function toggleActive(string $id): void
    {
        $template = WhatsAppMessageTemplate::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('update', $template);

        $template->update(['is_active' => ! $template->is_active]);

        unset($this->templates);
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
     * Deleting the catalog row never touches an already-sent Message —
     * its own template_name/template_language/template_variables columns
     * are a frozen snapshot, not a live reference back to this row (see
     * WhatsAppSender::sendTemplate()'s own docblock).
     */
    public function delete(string $id): void
    {
        $template = WhatsAppMessageTemplate::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('delete', $template);

        $template->delete();

        unset($this->templates);
        Flux::toast(variant: 'success', text: __('Template deleted.'));
    }
}
