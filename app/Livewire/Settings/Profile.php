<?php

namespace App\Livewire\Settings;

use App\Concerns\ProfileValidationRules;
use App\Models\TenantUser;
use Flux\Flux;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Profile settings')]
class Profile extends Component
{
    use ProfileValidationRules;

    public string $name = '';

    public string $email = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     * Refused outright for a Client Admin (see isLockedClientAdmin()) —
     * this form only ever touches name/email, and their own account is
     * provisioned by Platform Admin, not self-editable here or on the
     * Users page (see TenantUserPolicy::update()'s own self-action guard).
     * The view disables both fields for this case too; this is the
     * server-side backstop, not just a UI omission.
     */
    public function updateProfileInformation(): void
    {
        if ($this->isLockedClientAdmin()) {
            Flux::toast(variant: 'danger', text: __('Your name and email are managed by Platform Admin and cannot be changed here.'));

            return;
        }

        $user = Auth::user();

        $validated = $this->validate($this->profileRules($user->id));

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /**
     * See updateProfileInformation()'s own docblock.
     */
    #[Computed]
    public function isLockedClientAdmin(): bool
    {
        $user = Auth::user();

        return $user instanceof TenantUser && $user->isClientAdmin();
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Flux::toast(text: __('A new verification link has been sent to your email address.'));
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        $user = Auth::user();

        return $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        $user = Auth::user();

        return ! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail();
    }
}
