<?php

namespace App\Observers;

use App\Enums\PlatformUserType;
use App\Models\PlatformUser;

class PlatformUserObserver
{
    /**
     * The Mother Admin can never be deleted.
     */
    public function deleting(PlatformUser $platformUser): bool
    {
        return ! $platformUser->isMotherAdmin();
    }

    /**
     * The Mother Admin can never change type or be deactivated.
     */
    public function updating(PlatformUser $platformUser): bool
    {
        $wasMotherAdmin = $platformUser->getOriginal('user_type') === PlatformUserType::MotherAdmin;

        if (! $wasMotherAdmin) {
            return true;
        }

        if ($platformUser->isDirty('user_type')) {
            return false;
        }

        if ($platformUser->isDirty('is_active') && ! $platformUser->is_active) {
            return false;
        }

        return true;
    }
}
