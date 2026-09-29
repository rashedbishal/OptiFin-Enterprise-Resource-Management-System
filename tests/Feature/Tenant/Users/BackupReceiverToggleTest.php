<?php

use App\Enums\CallRoutingMode;
use App\Enums\TenantUserType;
use App\Livewire\Tenant\Users\Index;
use App\Models\Lead;
use App\Models\TenantUser;
use App\Services\CallRouter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;

/*
 * WhatsApp Call Backup / Overflow Receiver, Phase 4 — the Client Admin
 * control on the Users page for is_whatsapp_backup_receiver. This is the
 * first dedicated test file for App\Livewire\Tenant\Users\Index; no prior
 * suite existed to extend (confirmed by search before writing this file).
 *
 * The capability is deliberately NOT exposed through the create/edit modal
 * (CreateTenantUser::handle() always creates a plain ClientUser and never
 * reads this field at all — not hidden, genuinely absent from that code
 * path) — it is granted/revoked one user at a time via toggleBackupReceiver(),
 * the same shape as the pre-existing toggleActive().
 */

test('1. a newly created tenant user is never a backup receiver by default', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();

    Livewire::actingAs($admin)->test(Index::class)
        ->set('name', 'Rina Akter')
        ->set('email', 'rina@example.com')
        ->set('password', 'password123')
        ->set('role_id', $admin->role_id)
        ->call('save');

    $created = TenantUser::where('tenant_id', $tenant->id)->where('email', 'rina@example.com')->sole();
    expect($created->is_whatsapp_backup_receiver)->toBeFalse();
});

test('2. the create/edit save flow never accepts is_whatsapp_backup_receiver, even if a request tries to smuggle it in', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();

    // save() only ever reads its own validated array (name/email/password/
    // role_id) — there is no wire:model for this field to even bind to, so
    // this proves the field is structurally absent from that path, not
    // merely omitted from today's specific request.
    $component = Livewire::actingAs($admin)->test(Index::class)
        ->set('name', 'Tampered User')
        ->set('email', 'tampered@example.com')
        ->set('password', 'password123')
        ->set('role_id', $admin->role_id);

    // There is no public property for this field to even bind to — Livewire
    // itself refuses to set an undeclared property, which is exactly the
    // point being proven: the field is structurally unreachable through
    // this flow, not merely omitted from today's specific request.
    expect(fn () => $component->set('is_whatsapp_backup_receiver', true))
        ->toThrow(PublicPropertyNotFoundException::class);
    $component->call('save');

    $created = TenantUser::where('tenant_id', $tenant->id)->where('email', 'tampered@example.com')->sole();
    expect($created->is_whatsapp_backup_receiver)->toBeFalse();
});

test('4. a Client Admin can enable the capability for a same-tenant user, and it persists', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $target = clientUserWithLeadPermission($tenant, ['view', 'update']);
    expect($target->fresh()->is_whatsapp_backup_receiver)->toBeFalse();

    Livewire::actingAs($admin)->test(Index::class)->call('toggleBackupReceiver', $target->id);

    expect($target->fresh()->is_whatsapp_backup_receiver)->toBeTrue();
});

test('5. a Client Admin can disable the capability again, and it persists', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $target = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $target->update(['is_whatsapp_backup_receiver' => true]);

    Livewire::actingAs($admin)->test(Index::class)->call('toggleBackupReceiver', $target->id);

    expect($target->fresh()->is_whatsapp_backup_receiver)->toBeFalse();
});

test('6. a non-admin Client User cannot enable or disable the capability for anyone — they cannot reach this page at all', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $nonAdmin = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $target = clientUserWithLeadPermission($tenant, ['view', 'update']);

    // Index::mount() already requires TenantUserPolicy::viewAny() (Client
    // Admin only), so a non-admin is denied before toggleBackupReceiver()'s
    // own Gate::authorize('update', ...) is ever reached — the same single
    // enforcement point every other mutation on this page relies on, and the
    // same assertForbidden() pattern this codebase already uses for the
    // equivalent check on the Website Forms / Lead Reports admin pages.
    Livewire::actingAs($nonAdmin)->test(Index::class)->assertForbidden();

    expect($target->fresh()->is_whatsapp_backup_receiver)->toBeFalse();
});

test('7. a Client Admin cannot toggle a user from another tenant', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    ['tenant' => $otherTenant] = tenantWithLeadManagement();
    $foreignTarget = clientUserWithLeadPermission($otherTenant, ['view', 'update']);

    expect(fn () => Livewire::actingAs($admin)->test(Index::class)->call('toggleBackupReceiver', $foreignTarget->id))
        ->toThrow(ModelNotFoundException::class);

    expect($foreignTarget->fresh()->is_whatsapp_backup_receiver)->toBeFalse();
});

test('a Client Admin cannot grant the capability to another Client Admin row, even by direct call', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $secondAdmin = TenantUser::factory()->create([
        'tenant_id' => $tenant->id,
        'user_type' => TenantUserType::ClientAdmin,
        'role_id' => $admin->role_id,
    ]);

    Livewire::actingAs($admin)->test(Index::class)->call('toggleBackupReceiver', $secondAdmin->id);

    expect($secondAdmin->fresh()->is_whatsapp_backup_receiver)->toBeFalse();
});

test('8 & 9. toggling the capability changes nothing else about the user — role and active state are untouched', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $target = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $originalRoleId = $target->role_id;

    Livewire::actingAs($admin)->test(Index::class)->call('toggleBackupReceiver', $target->id);

    $fresh = $target->fresh();
    expect($fresh->role_id)->toBe($originalRoleId);
    expect($fresh->is_active)->toBeTrue();
});

test('10. toggling the capability changes no Lead and no lead assignment', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $target = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $admin->id]);
    $before = [Lead::count(), $lead->fresh()->assigned_to];

    Livewire::actingAs($admin)->test(Index::class)->call('toggleBackupReceiver', $target->id);

    expect([Lead::count(), $lead->fresh()->assigned_to])->toBe($before);
});

test('11. CallRouter sees the value this toggle persisted', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $assignee = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $assignee->update(['is_active' => false]);
    $backup = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $assignee->id]);

    Livewire::actingAs($admin)->test(Index::class)->call('toggleBackupReceiver', $backup->id);

    $routing = app(CallRouter::class)->route($lead->fresh(), leadCreatedByThisCall: false);

    expect($routing['routing_mode'])->toBe(CallRoutingMode::BackupFallback);
    expect($routing['routed_user_ids'])->toBe([$backup->id]);
});

test('12. revoking the capability through the UI removes future backup eligibility', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $assignee = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $assignee->update(['is_active' => false]);
    $backup = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $assignee->id]);

    $index = Livewire::actingAs($admin)->test(Index::class);
    $index->call('toggleBackupReceiver', $backup->id);
    expect(app(CallRouter::class)->route($lead->fresh(), leadCreatedByThisCall: false)['routing_mode'])
        ->toBe(CallRoutingMode::BackupFallback);

    $index->call('toggleBackupReceiver', $backup->id);

    $after = app(CallRouter::class)->route($lead->fresh(), leadCreatedByThisCall: false);
    expect($after['routing_mode'])->toBe(CallRoutingMode::AdminFallback);
    expect($after['routed_user_ids'])->toBe([$admin->id]);
});
