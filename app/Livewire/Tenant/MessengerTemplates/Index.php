<?php

namespace App\Livewire\Tenant\MessengerTemplates;

use App\Enums\LeadSourceType;
use App\Models\LeadSource;
use App\Models\MessengerMessageTemplate;
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
 * Tenant self-service catalog of Messenger Utility Message Templates
 * already created and approved directly in Meta's own tools — this app
 * never creates/submits one to Meta (see MessengerMessageTemplate's own
 * docblock for why). Mirrors WhatsAppTemplates\Index's create/edit/save/
 * confirmDelete/deleteConfirmed/delete shape, Client-Admin-only.
 *
 * Redirects to the hub the same way WhatsAppTemplates\Index does when the
 * tenant has no Messenger lead source — this catalog is meaningless
 * without one (see Dashboard::hasMessengerSource(), the same condition
 * that gates this page's own tile on the hub).
 */
#[Title('Messenger Templates')]
class Index extends Component
{
    public bool $showModal = false;

    public ?string $editingId = null;

    public string $sourceId = '';

    public string $name = '';

    public string $language = 'en';

    public string $variableCount = '0';

    public bool $showDeleteModal = false;

    public ?string $confirmingDeleteId = null;

    public function mount(): void
    {
        Gate::authorize('viewAny', MessengerMessageTemplate::class);

        if ($this->eligibleSources->isEmpty()) {
            $this->redirect(route('lead-management.index'), navigate: true);
        }
    }

    /**
     * This tenant's Messenger lead sources — the only type a template can
     * be registered against.
     *
     * @return Collection<int, LeadSource>
     */
    #[Computed]
    public function eligibleSources(): Collection
    {
        return LeadSource::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->where('type', LeadSourceType::FacebookMessenger)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, MessengerMessageTemplate>
     */
    #[Computed]
    public function templates(): Collection
    {
        return MessengerMessageTemplate::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->with('leadSource')
            ->orderByDesc('created_at')
            ->get();
    }

    public function create(): void
    {
        Gate::authorize('create', [MessengerMessageTemplate::class, Auth::user()->tenant]);

        $this->reset('editingId', 'name');
        $this->language = 'en';
        $this->variableCount = '0';
        $this->sourceId = (string) $this->eligibleSources->first()?->id;
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function edit(string $id): void
    {
        $template = MessengerMessageTemplate::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

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
                Rule::exists('lead_sources', 'id')->where('tenant_id', $tenant->id)->where('type', LeadSourceType::FacebookMessenger->value),
            ],
            // Meta's own naming rule for a template: lowercase letters,
            // numbers, and underscores only (same rule as WhatsApp's).
            'name' => ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'language' => ['required', 'string', 'max:35'],
            'variableCount' => ['required', 'integer', 'min:0', 'max:10'],
        ], [
            'name.regex' => __('Must match the exact template name approved for this Page — lowercase letters, numbers, and underscores only.'),
        ]);

        $duplicate = MessengerMessageTemplate::query()
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
        // pattern as WhatsAppTemplates\Index::save()'s identical check;
        // the real unique(lead_source_id, name, language) constraint (see
        // the migration) is what actually prevents a genuine race, this
        // catch just turns it into the same graceful validation error.
        try {
            if ($this->editingId) {
                $template = MessengerMessageTemplate::where('tenant_id', $tenant->id)->findOrFail($this->editingId);
                Gate::authorize('update', $template);

                $template->update([
                    'lead_source_id' => $validated['sourceId'],
                    'name' => $validated['name'],
                    'language' => $validated['language'],
                    'variable_count' => $validated['variableCount'],
                ]);
            } else {
                Gate::authorize('create', [MessengerMessageTemplate::class, $tenant]);

                MessengerMessageTemplate::create([
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
     * rows that already snapshotted it) rather than deleting.
     */
    public function toggleActive(string $id): void
    {
        $template = MessengerMessageTemplate::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

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
     * Deleting the catalog row never touches an already-sent Message — its
     * own template_name/template_language/template_variables columns are a
     * frozen snapshot, not a live reference back to this row (see
     * MessengerSender::sendTemplate()'s own docblock).
     */
    public function delete(string $id): void
    {
        $template = MessengerMessageTemplate::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('delete', $template);

        $template->delete();

        unset($this->templates);
        Flux::toast(variant: 'success', text: __('Template deleted.'));
    }
}
