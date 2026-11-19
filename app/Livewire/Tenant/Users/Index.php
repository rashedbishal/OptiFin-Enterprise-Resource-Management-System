<?php

namespace App\Livewire\Tenant\Users;

use App\Actions\Tenant\CreateTenantUser;
use App\Actions\Tenant\UnpairCallingAppDevice;
use App\Enums\LeadSourceType;
use App\Enums\TenantUserType;
use App\Models\CallingAppDevice;
use App\Models\Role;
use App\Models\TenantUser;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Users')]
class Index extends Component
{
    public bool $showModal = false;

    public ?string $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public ?string $role_id = null;

    public bool $showDeleteModal = false;

    public ?string $confirmingDeleteId = null;

    public bool $showUnpairCallingAppModal = false;

    public ?string $confirmingUnpairCallingAppId = null;

    public function mount(): void
    {
        Gate::authorize('viewAny', [TenantUser::class, Auth::user()->tenant]);
    }

    #[Computed]
    public function users()
    {
        return TenantUser::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->with(['role', 'callingAppDevice'])
            ->latest()
            ->paginate(10);
    }

    #[Computed]
    public function roles()
    {
        return Role::query()->where('tenant_id', Auth::user()->tenant_id)->orderBy('name')->get();
    }

    public function create(): void
    {
        Gate::authorize('create', [TenantUser::class, Auth::user()->tenant]);

        $this->reset('editingId', 'name', 'email', 'password', 'role_id');
        $this->showModal = true;
    }

    public function edit(string $id): void
    {
        $target = TenantUser::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('update', $target);

        $this->editingId = $target->id;
        $this->name = $target->name;
        $this->email = $target->email;
        $this->password = '';
        $this->role_id = $target->role_id;
        $this->showModal = true;
    }

    public function save(): void
    {
        $tenant = Auth::user()->tenant;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('tenant_users', 'email')->ignore($this->editingId),
            ],
            'password' => [$this->editingId ? 'nullable' : 'required', 'string', 'min:8'],
            'role_id' => ['required', 'exists:roles,id'],
        ]);

        if ($this->editingId) {
            $target = TenantUser::where('tenant_id', $tenant->id)->findOrFail($this->editingId);
            Gate::authorize('update', $target);

            $target->fill([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'role_id' => $validated['role_id'],
            ]);

            if ($validated['password']) {
                $target->password = $validated['password'];
            }

            $target->save();
        } else {
            Gate::authorize('create', [TenantUser::class, $tenant]);

            app(CreateTenantUser::class)->handle($tenant, $validated);
        }

        $this->showModal = false;
        Flux::toast(variant: 'success', text: __('User saved.'));
    }

    public function toggleActive(string $id): void
    {
        $target = TenantUser::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('update', $target);

        $target->update(['is_active' => ! $target->is_active]);
    }

    /**
     * WhatsApp Call Backup / Overflow Receiver — Client-Admin-only toggle,
     * same authorization/tenant-scoping shape as toggleActive() above and
     * updating only this one field. A Client Admin row is never eligible
     * (they already receive every AdminFallback call unconditionally, see
     * App\Services\CallRouter) — the view already hides this action for
     * them, and this guard is the server-side backstop for that rule, not
     * merely a UI omission. A user whose role already receives unassigned
     * WhatsApp leads (receivesUnassignedWhatsAppLeads() below) is excluded
     * the same way, for the same reason: App\Services\CallRouter already
     * rings them simultaneously for an unassigned lead's call, making the
     * busy-assignee-overflow toggle redundant for the sources that matter
     * to them.
     */
    public function toggleBackupReceiver(string $id): void
    {
        $target = TenantUser::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('update', $target);

        if ($target->user_type === TenantUserType::ClientAdmin || $this->receivesUnassignedWhatsAppLeads($target)) {
            return;
        }

        $target->update(['is_whatsapp_backup_receiver' => ! $target->is_whatsapp_backup_receiver]);
    }

    /**
     * Whether this user's role has been granted "receive unassigned leads"
     * access (see LeadSource::unassignedRoles()'s own docblock) to any
     * WhatsApp-type source — the one condition, besides being a Client
     * Admin, that suppresses the Backup Calls toggle both here and in the
     * Blade view, per toggleBackupReceiver()'s own docblock.
     */
    public function receivesUnassignedWhatsAppLeads(TenantUser $user): bool
    {
        return $user->role->unassignedLeadSources()->where('type', LeadSourceType::Whatsapp)->exists();
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

    public function delete(string $id): void
    {
        $target = TenantUser::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('delete', $target);

        $target->delete();

        Flux::toast(variant: 'success', text: __('User deleted.'));
    }

    public function confirmUnpairCallingApp(string $id): void
    {
        $this->confirmingUnpairCallingAppId = $id;
        $this->showUnpairCallingAppModal = true;
    }

    /**
     * A Client Admin unpairs another user's WhatsApp calling app (e.g. a
     * lost phone, or someone leaving). Same 'update' gate as every other
     * per-user action on this page — their own phone is unpaired from
     * Settings → Calling app instead.
     */
    public function unpairCallingAppConfirmed(UnpairCallingAppDevice $action): void
    {
        $id = $this->confirmingUnpairCallingAppId;

        $this->showUnpairCallingAppModal = false;
        $this->confirmingUnpairCallingAppId = null;

        if ($id === null) {
            return;
        }

        $actor = Auth::guard('web')->user();

        abort_unless($actor instanceof TenantUser, 403);

        $target = TenantUser::query()->where('tenant_id', $actor->tenant_id)->whereKey($id)->firstOrFail();

        Gate::authorize('update', $target);

        $device = CallingAppDevice::query()->where('tenant_user_id', $target->id)->first();

        if ($device !== null) {
            $action->handle($device);
        }

        unset($this->users);

        Flux::toast(variant: 'success', text: __('Calling app unpaired.'));
    }
}
