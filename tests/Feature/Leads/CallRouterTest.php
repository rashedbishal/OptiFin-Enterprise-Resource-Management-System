<?php

use App\Enums\CallRoutingMode;
use App\Enums\TenantUserType;
use App\Models\Lead;
use App\Models\TenantUser;
use App\Services\CallRouter;
use Illuminate\Support\Facades\DB;

/*
 * CallRouter decides, once and read-only, who an inbound call is routed to.
 * "Can take calls" = active + the lead-management `update` permission — the
 * bar the later answering step will enforce.
 */

/**
 * @return array{tenant: mixed, admin: TenantUser, lead: Lead}
 */
function callRouterScenario(): array
{
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    return compact('tenant', 'admin', 'lead');
}

/**
 * The model refuses to deactivate a tenant's last active Client Admin (an
 * existing business rule these routing tests are not about), so the flag is
 * flipped at the table level.
 */
function callRouterDeactivate(TenantUser $user): void
{
    DB::table('tenant_users')->where('id', $user->id)->update(['is_active' => false]);
}

function callRouterSecondAdmin($tenant, TenantUser $firstAdmin): TenantUser
{
    return TenantUser::factory()->create([
        'tenant_id' => $tenant->id,
        'user_type' => TenantUserType::ClientAdmin,
        'role_id' => $firstAdmin->role_id,
    ]);
}

test('an active assignee who can take calls is the only one routed', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $x['lead']->update(['assigned_to' => $assignee->id]);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::Assignee);
    expect($routing['routed_user_ids'])->toBe([$assignee->id]);
    expect($routing['assignee_id_at_arrival'])->toBe($assignee->id);
});

test('an unassigned lead routes to every active Client Admin who can take calls, in id order', function () {
    $x = callRouterScenario();
    $second = callRouterSecondAdmin($x['tenant'], $x['admin']);
    TenantUser::factory()->create(['tenant_id' => $x['tenant']->id, 'user_type' => TenantUserType::ClientAdmin, 'role_id' => $x['admin']->role_id, 'is_active' => false]);

    $routing = app(CallRouter::class)->route($x['lead'], leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::AdminFallback);
    expect($routing['routed_user_ids'])->toBe(collect([$x['admin']->id, $second->id])->sort()->values()->all());
    expect($routing['assignee_id_at_arrival'])->toBeNull();
});

test('a Client User who is not a Client Admin is never part of the admin fallback', function () {
    $x = callRouterScenario();
    clientUserWithLeadPermission($x['tenant'], ['view', 'update']);

    $routing = app(CallRouter::class)->route($x['lead'], leadCreatedByThisCall: false);

    expect($routing['routed_user_ids'])->toBe([$x['admin']->id]);
});

test('an inactive assignee falls back to the admins but is still recorded as the assignee at arrival', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $assignee->update(['is_active' => false]);
    $x['lead']->update(['assigned_to' => $assignee->id]);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::AdminFallback);
    expect($routing['routed_user_ids'])->toBe([$x['admin']->id]);
    expect($routing['assignee_id_at_arrival'])->toBe($assignee->id);
});

test('an assignee who cannot update leads is unusable, so the call falls back to the admins', function () {
    $x = callRouterScenario();
    $viewOnly = clientUserWithLeadPermission($x['tenant'], ['view']);
    $x['lead']->update(['assigned_to' => $viewOnly->id]);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::AdminFallback);
    expect($routing['routed_user_ids'])->toBe([$x['admin']->id]);
});

test('an assignee from another tenant is never routed to', function () {
    $x = callRouterScenario();
    ['tenant' => $otherTenant] = tenantWithLeadManagement();
    $foreign = clientUserWithLeadPermission($otherTenant, ['view', 'update']);
    $x['lead']->forceFill(['assigned_to' => $foreign->id])->save();

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::AdminFallback);
    expect($routing['routed_user_ids'])->toBe([$x['admin']->id]);
});

test('with nobody eligible the routing is None with an empty list', function () {
    $x = callRouterScenario();
    callRouterDeactivate($x['admin']);

    $routing = app(CallRouter::class)->route($x['lead'], leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::None);
    expect($routing['routed_user_ids'])->toBe([]);
});

test('admins of another tenant are never routed to', function () {
    $x = callRouterScenario();
    callRouterDeactivate($x['admin']);
    tenantWithLeadManagement();

    $routing = app(CallRouter::class)->route($x['lead'], leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::None);
});

test('a lead created by this very call always routes to the admins, even if it already has an assignee', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    // Simulates a synchronous queue having auto-assigned the brand-new lead.
    $x['lead']->update(['assigned_to' => $assignee->id]);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: true);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::AdminFallback);
    expect($routing['routed_user_ids'])->toBe([$x['admin']->id]);
    expect($routing['assignee_id_at_arrival'])->toBe($assignee->id);
});

test('routing is read-only: it changes no lead and no user', function () {
    $x = callRouterScenario();
    $before = [Lead::count(), TenantUser::count(), $x['lead']->fresh()->updated_at->toIso8601String()];

    app(CallRouter::class)->route($x['lead'], leadCreatedByThisCall: false);

    expect([Lead::count(), TenantUser::count(), $x['lead']->fresh()->updated_at->toIso8601String()])->toBe($before);
});
