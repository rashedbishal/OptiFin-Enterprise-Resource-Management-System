<?php

namespace App\Livewire\Settings;

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use App\Models\TenantUser;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class DeleteUserForm extends Component
{
    use PasswordValidationRules;

    public string $password = '';

    /**
     * See deleteUser()'s own docblock.
     */
    #[Computed]
    public function isLockedClientAdmin(): bool
    {
        $user = Auth::user();

        return $user instanceof TenantUser && $user->isClientAdmin();
    }

    /**
     * Delete the currently authenticated user. Refused for a Client Admin
     * — this generic starter-kit form has no policy check of its own
     * (unlike the Users page, which goes through TenantUserPolicy::
     * delete()'s self-action guard), so without this a Client Admin could
     * delete their own account here even though the Users page blocks it.
     * Platform Admin's own self-delete behavior is untouched.
     */
    public function deleteUser(Logout $logout): void
    {
        $user = Auth::user();

        if ($user instanceof TenantUser && $user->isClientAdmin()) {
            Flux::toast(variant: 'danger', text: __('Your account was provisioned by Platform Admin and cannot be deleted here.'));

            return;
        }

        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        tap($user, $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}
