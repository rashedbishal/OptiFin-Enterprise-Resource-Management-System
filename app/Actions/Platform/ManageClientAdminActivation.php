<?php

namespace App\Actions\Platform;

use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Support\Facades\DB;

/**
 * A tenant's Client Admin account is provisioned by Platform Admin (see
 * CreateClient), so only Platform Admin — never the Client Admin
 * themselves, see TenantUserPolicy's self-action guard on update()/
 * delete() — can deactivate or reactivate it. Deactivating suspends every
 * other active user in the tenant alongside the Client Admin (in practice
 * the Client Admin is the one who creates them all, via Tenant\Users\
 * Index::create()); reactivating restores exactly the users THIS action
 * put to sleep (see TenantUser::$deactivated_by_platform_at's own
 * migration docblock), never a user who was already inactive for an
 * independent reason before it ran, and never a user some other path
 * (e.g. a still-active Client Admin's own Index::toggleActive()) has
 * since reactivated on its own.
 */
class ManageClientAdminActivation
{
    public function deactivate(Tenant $tenant): void
    {
        DB::transaction(function () use ($tenant): void {
            $activeUsers = TenantUser::query()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->get();

            foreach ($activeUsers as $user) {
                // TenantUserObserver otherwise blocks deactivating the
                // tenant's last active owner — a deliberate guard against
                // the Client Admin disabling themselves via the Users
                // page. This is the one, explicit code path allowed to
                // bypass it.
                if ($user->isClientAdmin()) {
                    $user->allowsPlatformOwnerActivationChange = true;
                }

                $user->update([
                    'is_active' => false,
                    'deactivated_by_platform_at' => now(),
                ]);
            }
        });
    }

    public function activate(Tenant $tenant): void
    {
        DB::transaction(function () use ($tenant): void {
            TenantUser::query()
                ->where('tenant_id', $tenant->id)
                ->whereNotNull('deactivated_by_platform_at')
                ->lockForUpdate()
                ->get()
                ->each(fn (TenantUser $user) => $user->update([
                    'is_active' => true,
                    'deactivated_by_platform_at' => null,
                ]));
        });
    }
}
