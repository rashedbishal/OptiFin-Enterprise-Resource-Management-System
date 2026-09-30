<?php

use App\Actions\Tenant\ListActiveLeadCalls;
use App\Enums\CallRoutingMode;
use App\Enums\TenantUserType;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\LeadSource;
use App\Models\TenantUser;
use App\Services\CallRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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

/**
 * A backup-flagged Client User who can take calls, for the WhatsApp Call
 * Backup / Overflow Receiver tests below.
 */
function callRouterBackupReceiver($tenant): TenantUser
{
    $user = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $user->update(['is_whatsapp_backup_receiver' => true]);

    return $user->fresh();
}

/**
 * Leaves this user "busy": owning another active (Answering) LeadCall of the
 * same tenant — the one concrete busy signal CallRouter uses.
 */
function callRouterMakeBusy(TenantUser $user): LeadCall
{
    $source = LeadSource::factory()->create(['tenant_id' => $user->tenant_id]);
    $otherLead = Lead::factory()->create(['tenant_id' => $user->tenant_id, 'source_id' => $source->id]);

    return LeadCall::factory()->answering()->create([
        'tenant_id' => $user->tenant_id,
        'lead_id' => $otherLead->id,
        'lead_source_id' => $source->id,
        'claimed_by' => $user->id,
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

// ── WhatsApp Call Backup / Overflow Receiver (Phase 3) ──────────────────────

test('1. an available, not-busy assignee is routed alone — a backup receiver in the tenant is never included', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $x['lead']->update(['assigned_to' => $assignee->id]);
    callRouterBackupReceiver($x['tenant']);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::Assignee);
    expect($routing['routed_user_ids'])->toBe([$assignee->id]);
});

test('2. with zero users holding the backup-receiver flag, every pre-existing routing outcome is unchanged', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $assignee->update(['is_active' => false]);
    $x['lead']->update(['assigned_to' => $assignee->id]);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    // Identical to the pre-Phase-3 "an inactive assignee falls back to the
    // admins" test above: no BackupFallback tier is ever reached when nobody
    // in the tenant has the capability.
    expect($routing['routing_mode'])->toBe(CallRoutingMode::AdminFallback);
    expect($routing['routed_user_ids'])->toBe([$x['admin']->id]);
    expect($routing['assignee_id_at_arrival'])->toBe($assignee->id);
});

test('3. an inactive assignee with an eligible backup receiver routes to BackupFallback', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $assignee->update(['is_active' => false]);
    $x['lead']->update(['assigned_to' => $assignee->id]);
    $backup = callRouterBackupReceiver($x['tenant']);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::BackupFallback);
    expect($routing['routed_user_ids'])->toBe([$backup->id]);
    expect($routing['assignee_id_at_arrival'])->toBe($assignee->id);
});

test('4. an assignee lacking lead-management update permission with an eligible backup routes to BackupFallback', function () {
    $x = callRouterScenario();
    $viewOnly = clientUserWithLeadPermission($x['tenant'], ['view']);
    $x['lead']->update(['assigned_to' => $viewOnly->id]);
    $backup = callRouterBackupReceiver($x['tenant']);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::BackupFallback);
    expect($routing['routed_user_ids'])->toBe([$backup->id]);
});

test('5. an assignee already busy on another active call, with an eligible backup, routes to BackupFallback', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $x['lead']->update(['assigned_to' => $assignee->id]);
    callRouterMakeBusy($assignee);
    $backup = callRouterBackupReceiver($x['tenant']);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::BackupFallback);
    expect($routing['routed_user_ids'])->toBe([$backup->id]);
});

test('6. multiple eligible backup receivers are all routed simultaneously', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $assignee->update(['is_active' => false]);
    $x['lead']->update(['assigned_to' => $assignee->id]);
    $backupA = callRouterBackupReceiver($x['tenant']);
    $backupB = callRouterBackupReceiver($x['tenant']);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::BackupFallback);
    expect($routing['routed_user_ids'])->toBe(collect([$backupA->id, $backupB->id])->sort()->values()->all());
});

test('7. an inactive backup receiver is excluded', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $assignee->update(['is_active' => false]);
    $x['lead']->update(['assigned_to' => $assignee->id]);
    $backup = callRouterBackupReceiver($x['tenant']);
    $backup->update(['is_active' => false]);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    // No eligible backup after all → falls through to the existing,
    // unmodified AdminFallback tier.
    expect($routing['routing_mode'])->toBe(CallRoutingMode::AdminFallback);
    expect($routing['routed_user_ids'])->toBe([$x['admin']->id]);
});

test('8. a backup receiver lacking lead-management update permission is excluded', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $assignee->update(['is_active' => false]);
    $x['lead']->update(['assigned_to' => $assignee->id]);
    $viewOnlyBackup = clientUserWithLeadPermission($x['tenant'], ['view']);
    $viewOnlyBackup->update(['is_whatsapp_backup_receiver' => true]);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::AdminFallback);
    expect($routing['routed_user_ids'])->toBe([$x['admin']->id]);
});

test('9. a backup receiver already busy on another active call is excluded', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $assignee->update(['is_active' => false]);
    $x['lead']->update(['assigned_to' => $assignee->id]);
    $busyBackup = callRouterBackupReceiver($x['tenant']);
    callRouterMakeBusy($busyBackup);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::AdminFallback);
    expect($routing['routed_user_ids'])->toBe([$x['admin']->id]);
});

test('10. a backup receiver from another tenant is never routed to', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $assignee->update(['is_active' => false]);
    $x['lead']->update(['assigned_to' => $assignee->id]);
    ['tenant' => $otherTenant] = tenantWithLeadManagement();
    callRouterBackupReceiver($otherTenant);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::AdminFallback);
    expect($routing['routed_user_ids'])->toBe([$x['admin']->id]);
});

test('11. an ineligible assignee with no eligible backup anywhere falls through to the existing AdminFallback', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $assignee->update(['is_active' => false]);
    $x['lead']->update(['assigned_to' => $assignee->id]);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::AdminFallback);
    expect($routing['routed_user_ids'])->toBe([$x['admin']->id]);
});

test('12. an unassigned lead never runs backup routing, even when an eligible backup receiver exists', function () {
    $x = callRouterScenario();
    callRouterBackupReceiver($x['tenant']);

    $routing = app(CallRouter::class)->route($x['lead'], leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::AdminFallback);
    expect($routing['routed_user_ids'])->toBe([$x['admin']->id]);
    expect($routing['assignee_id_at_arrival'])->toBeNull();
});

test('13. nobody eligible at all — assignee, backups, or admins — is still routing_mode None with an empty list', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $assignee->update(['is_active' => false]);
    $x['lead']->update(['assigned_to' => $assignee->id]);
    callRouterDeactivate($x['admin']);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::None);
    expect($routing['routed_user_ids'])->toBe([]);
});

test('14. the assignee never appears as their own backup candidate', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $assignee->update(['is_whatsapp_backup_receiver' => true]);
    $x['lead']->update(['assigned_to' => $assignee->id]);
    callRouterMakeBusy($assignee);
    $otherBackup = callRouterBackupReceiver($x['tenant']);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::BackupFallback);
    expect($routing['routed_user_ids'])->toBe([$otherBackup->id])
        ->and($routing['routed_user_ids'])->not->toContain($assignee->id);
});

test('15. routing a BackupFallback call changes no Lead and no TenantUser row', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $assignee->update(['is_active' => false]);
    $x['lead']->update(['assigned_to' => $assignee->id]);
    callRouterBackupReceiver($x['tenant']);

    $lead = $x['lead']->fresh();
    $before = [Lead::count(), TenantUser::count(), $lead->assigned_to, $lead->updated_at->toIso8601String()];

    $routing = app(CallRouter::class)->route($lead, leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::BackupFallback);
    expect([Lead::count(), TenantUser::count(), $lead->fresh()->assigned_to, $lead->fresh()->updated_at->toIso8601String()])->toBe($before);
});

test('revoking is_whatsapp_backup_receiver stops the next call from routing to that user, without touching any already-frozen call', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $assignee->update(['is_active' => false]);
    $x['lead']->update(['assigned_to' => $assignee->id]);
    $backup = callRouterBackupReceiver($x['tenant']);

    $before = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);
    expect($before['routing_mode'])->toBe(CallRoutingMode::BackupFallback);
    expect($before['routed_user_ids'])->toBe([$backup->id]);

    $backup->update(['is_whatsapp_backup_receiver' => false]);

    $after = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    // No eligible backup left → the next call falls through to the existing,
    // unmodified AdminFallback tier, exactly as it would for a tenant that
    // never had a backup receiver at all.
    expect($after['routing_mode'])->toBe(CallRoutingMode::AdminFallback);
    expect($after['routed_user_ids'])->toBe([$x['admin']->id]);
});

test('Phase 7 audit fix: the busy check for backup candidates is one batched query, not one per candidate', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $assignee->update(['is_active' => false]);
    $x['lead']->update(['assigned_to' => $assignee->id]);
    foreach (range(1, 5) as $i) {
        callRouterBackupReceiver($x['tenant']);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);
    $leadCallQueries = array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql): bool => str_contains($sql, 'from "lead_calls"'));

    expect($routing['routing_mode'])->toBe(CallRoutingMode::BackupFallback);
    expect($routing['routed_user_ids'])->toHaveCount(5);
    // The assignee here is inactive, so rule 1's own isBusy() check is never
    // reached at all (the TenantUser lookup itself already filters on
    // is_active and returns null) -- the single lead_calls query below is
    // entirely the batched check for the 5 backup candidates together,
    // never one query per candidate.
    expect($leadCallQueries)->toHaveCount(1);
});

test('Phase 7 audit fix: an active-but-busy assignee plus busy check still adds exactly one query per rule, never per candidate', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $x['lead']->update(['assigned_to' => $assignee->id]);
    callRouterMakeBusy($assignee);
    foreach (range(1, 5) as $i) {
        callRouterBackupReceiver($x['tenant']);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);
    $leadCallQueries = array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql): bool => str_contains($sql, 'from "lead_calls"'));

    expect($routing['routing_mode'])->toBe(CallRoutingMode::BackupFallback);
    expect($routing['routed_user_ids'])->toHaveCount(5);
    // One query for the assignee's own isBusy() (rule 1, reached this time
    // since the assignee is active and permitted) plus one batched query
    // for all 5 backup candidates together (rule 2) -- two total, not six.
    expect($leadCallQueries)->toHaveCount(2);
});

// ── WhatsApp Call Backup / Overflow Receiver — assignee softphone presence ──
//
// Confirmed production gap: an assignee who is active, permitted and not
// busy on another call was ALWAYS routed alone, even when nobody was actually
// at their softphone to answer -- there was no signal for "not currently
// reachable" short of the DB-level is_active/permission/busy checks above.
// SoftphonePresence closes that gap for the assignee only (rule 1); backup
// and admin candidates are deliberately never presence-checked -- see
// eligibleBackupReceivers()'s own note.

test('a fresh softphone heartbeat keeps an otherwise-eligible assignee routed alone', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $assignee->forceFill(['softphone_last_seen_at' => now()])->save();
    $x['lead']->update(['assigned_to' => $assignee->id]);
    callRouterBackupReceiver($x['tenant']);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::Assignee);
    expect($routing['routed_user_ids'])->toBe([$assignee->id]);
});

test('an assignee who is otherwise eligible but presence-stale falls through to BackupFallback', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $assignee->forceFill(['softphone_last_seen_at' => now()->subSeconds(200)])->save();
    $x['lead']->update(['assigned_to' => $assignee->id]);
    $backup = callRouterBackupReceiver($x['tenant']);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::BackupFallback);
    expect($routing['routed_user_ids'])->toBe([$backup->id]);
    expect($routing['routed_user_ids'])->not->toContain($assignee->id);
    expect($routing['assignee_id_at_arrival'])->toBe($assignee->id);
});

test('an assignee who has never opened the softphone (no heartbeat yet) is still treated as available — backward compatible default', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    expect($assignee->softphone_last_seen_at)->toBeNull();
    $x['lead']->update(['assigned_to' => $assignee->id]);
    callRouterBackupReceiver($x['tenant']);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::Assignee);
    expect($routing['routed_user_ids'])->toBe([$assignee->id]);
});

test('a presence-stale assignee with no eligible backup still falls through to the existing AdminFallback', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $assignee->forceFill(['softphone_last_seen_at' => now()->subSeconds(200)])->save();
    $x['lead']->update(['assigned_to' => $assignee->id]);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::AdminFallback);
    expect($routing['routed_user_ids'])->toBe([$x['admin']->id]);
});

test('presence is never consulted for an unassigned lead — it still routes straight to AdminFallback', function () {
    $x = callRouterScenario();
    callRouterBackupReceiver($x['tenant']);

    $routing = app(CallRouter::class)->route($x['lead'], leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::AdminFallback);
    expect($routing['routed_user_ids'])->toBe([$x['admin']->id]);
});

test('outbound authorization and eligibility are untouched by presence: a stale user can still place a call, only inbound routing changes', function () {
    // Presence only ever gates CallRouter's rule 1 (an inbound assignee).
    // Nothing about outbound calling (InitiateOutboundCall's own
    // Gate::authorize('update', $lead)) reads softphone_last_seen_at at all.
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $assignee->forceFill(['softphone_last_seen_at' => now()->subSeconds(200)])->save();
    $x['lead']->update(['assigned_to' => $assignee->id]);

    expect(Gate::forUser($assignee->fresh())->allows('update', $x['lead']->fresh()))->toBeTrue();
});

test('the exact reported production scenario: a stale assignee (User A) triggers BackupFallback to an eligible backup receiver (User B), and only User B\'s active-call list returns it', function () {
    $x = callRouterScenario();

    // User A: assigned to the lead, active, correct permission, NOT busy —
    // eligible by every DB-level signal — but their softphone has gone
    // quiet (no poll in over 3 minutes, well past the 45s default
    // threshold): exactly the field-observed gap.
    $userA = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $userA->forceFill(['softphone_last_seen_at' => now()->subMinutes(3)])->save();
    $x['lead']->update(['assigned_to' => $userA->id]);

    // User B: active, correct permission, opted into backup receiving, not busy.
    $userB = callRouterBackupReceiver($x['tenant']);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::BackupFallback);
    expect($routing['routed_user_ids'])->toBe([$userB->id]);
    expect($routing['routed_user_ids'])->not->toContain($userA->id);
    expect($routing['assignee_id_at_arrival'])->toBe($userA->id);
    // Lead.assigned_to is never touched by routing.
    expect($x['lead']->fresh()->assigned_to)->toBe($userA->id);

    // Persist the call the way RecordInboundCall's insertRow() would, using
    // this exact routing snapshot, then confirm the two halves of the
    // pipeline this bug fell between actually connect: the row User B is
    // now entitled to see, and the read that decides who sees what.
    $source = LeadSource::factory()->create(['tenant_id' => $x['tenant']->id]);
    $call = LeadCall::factory()->create([
        'tenant_id' => $x['tenant']->id,
        'lead_id' => $x['lead']->id,
        'lead_source_id' => $source->id,
        'routing_mode' => $routing['routing_mode'],
        'routed_user_ids' => $routing['routed_user_ids'],
        'assignee_id_at_arrival' => $routing['assignee_id_at_arrival'],
    ]);

    $forUserB = app(ListActiveLeadCalls::class)->handle($userB);
    expect(array_column($forUserB, 'id'))->toBe([$call->id]);
    expect($forUserB[0]['receiving_as'])->toBe('backup');
    expect($forUserB[0]['assignee'])->toBe(['id' => $userA->id, 'name' => $userA->name]);

    expect(app(ListActiveLeadCalls::class)->handle($userA->fresh()))->toBe([]);
});

// ── WhatsApp Call Backup / Overflow Receiver, Phase 2 — Reverb presence ────
//
// CallRouter itself is untouched this phase (it still only ever calls
// $this->presence->isAvailable($assignee) — see its own docblock); these
// tests prove the Reverb-aware SoftphonePresence correctly drives routing
// through the unchanged CallRouter, via fakeReverbPresence() (tests/Pest.php)
// — never a real Reverb server.

test('Reverb reports the assignee present -> Assignee, even with a stale heartbeat', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $assignee->forceFill(['softphone_last_seen_at' => now()->subDays(1)])->save();
    $x['lead']->update(['assigned_to' => $assignee->id]);
    callRouterBackupReceiver($x['tenant']);

    fakeReverbPresence()->returningMembers([$assignee->id]);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::Assignee);
    expect($routing['routed_user_ids'])->toBe([$assignee->id]);
});

test('Reverb reports the assignee absent -> BackupFallback when an eligible backup exists', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $assignee->forceFill(['softphone_last_seen_at' => now()])->save();
    $x['lead']->update(['assigned_to' => $assignee->id]);
    $backup = callRouterBackupReceiver($x['tenant']);

    fakeReverbPresence()->returningMembers([]);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::BackupFallback);
    expect($routing['routed_user_ids'])->toBe([$backup->id]);
    expect($routing['routed_user_ids'])->not->toContain($assignee->id);
});

test('Reverb reports the assignee absent, no eligible backup -> AdminFallback', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $x['lead']->update(['assigned_to' => $assignee->id]);

    fakeReverbPresence()->returningMembers([]);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::AdminFallback);
    expect($routing['routed_user_ids'])->toBe([$x['admin']->id]);
});

test('an unassigned lead never queries Reverb at all -> still AdminFallback, never BackupFallback', function () {
    $x = callRouterScenario();
    callRouterBackupReceiver($x['tenant']);
    $fake = fakeReverbPresence()->returningMembers([]);

    $routing = app(CallRouter::class)->route($x['lead'], leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::AdminFallback);
    expect($routing['routed_user_ids'])->toBe([$x['admin']->id]);
    expect($fake->queriedChannels)->toBe([]);
});

test('backup receiver eligibility is unaffected by Reverb presence — a backup is never itself presence-checked', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $x['lead']->update(['assigned_to' => $assignee->id]);
    $backup = callRouterBackupReceiver($x['tenant']);

    // The fake only ever "knows about" the assignee — the backup is never
    // in its member list, yet must still be routed to once the assignee is
    // absent. If eligibleBackupReceivers() ever started presence-checking
    // backups too, this would wrongly fall through to AdminFallback instead.
    fakeReverbPresence()->returningMembers([]);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::BackupFallback);
    expect($routing['routed_user_ids'])->toBe([$backup->id]);
});

test('performance: exactly one Reverb presence lookup per routing decision, never one per backup candidate', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $x['lead']->update(['assigned_to' => $assignee->id]);
    foreach (range(1, 5) as $i) {
        callRouterBackupReceiver($x['tenant']);
    }

    $fake = fakeReverbPresence()->returningMembers([]);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::BackupFallback);
    expect($routing['routed_user_ids'])->toHaveCount(5);
    // Exactly one query — for the assignee. Never one per backup candidate,
    // and never a second one for the backup tier itself.
    expect($fake->queriedChannels)->toHaveCount(1);
});

test('a Reverb outage never fails routing — it silently falls through to AdminFallback\'s pre-existing behavior via the timestamp fallback', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $x['lead']->update(['assigned_to' => $assignee->id]);

    fakeReverbPresence()->failing();

    // No exception, no 500 — routing still resolves, using the unchanged
    // timestamp fallback (null heartbeat -> available -> Assignee).
    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::Assignee);
    expect($routing['routed_user_ids'])->toBe([$assignee->id]);
});

test('Reverb presence is tenant-scoped: an assignee in tenant A is never affected by tenant B\'s membership data', function () {
    $x = callRouterScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $x['lead']->update(['assigned_to' => $assignee->id]);

    $otherTenant = tenantWithLeadManagement();
    $foreignUserWithSameShapeId = clientUserWithLeadPermission($otherTenant['tenant'], ['view', 'update']);

    // The fake "knows about" a member from a DIFFERENT tenant only — proving
    // presence for tenant A's assignee is decided by tenant A's own channel
    // query (asserted below), not by whatever the fake happens to return.
    $fake = fakeReverbPresence()->returningMembers([$foreignUserWithSameShapeId->id]);

    $routing = app(CallRouter::class)->route($x['lead']->fresh(), leadCreatedByThisCall: false);

    // Absent (the assignee's own id was never in the returned list) -> falls
    // through exactly like any other Absent case.
    expect($routing['routing_mode'])->toBe(CallRoutingMode::AdminFallback);
    expect($fake->queriedChannels)->toBe(['presence-tenant-softphone.'.$x['tenant']->id]);
});
