<?php

namespace App\Livewire\Tenant\Leads\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Deleting a lead needs the acting tenant user to re-type their own current
 * password, not just a plain Yes/No confirmation — a lead carries a
 * client's CRM history, so this is a deliberately heavier gate than the
 * plain confirm-modal used for lower-stakes deletes elsewhere in the app
 * (lead sources, statuses, roles, ...). Shared by Index (table/Kanban
 * delete) and Show (detail page/flyout delete) since both need the exact
 * same check.
 */
trait ConfirmsLeadDeletionPassword
{
    public string $deletePassword = '';

    /**
     * Validates the password field is present, then checks it against the
     * currently authenticated tenant user's own password — never the lead's
     * tenant in the abstract, always whoever is actually logged in and
     * clicking delete. Clears the field on success so it never lingers
     * across modal opens; leaves it (with an inline error) on failure so
     * the user can correct a typo without retyping from scratch.
     */
    protected function verifyDeletePassword(): bool
    {
        $this->validate(['deletePassword' => ['required', 'string']]);

        if (! Hash::check($this->deletePassword, Auth::user()->password)) {
            $this->addError('deletePassword', __('The password is incorrect.'));

            return false;
        }

        $this->reset('deletePassword');

        return true;
    }
}
