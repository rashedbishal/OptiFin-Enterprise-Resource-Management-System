<?php

use App\Models\TenantUser;

/*
 * WhatsApp Call Backup / Overflow Receiver, Phase 2 — the raw data-model
 * layer only (migration + Fillable + cast). Routing behavior belongs to
 * CallRouter's own tests (Phase 3); the Users create/edit UI belongs to its
 * own tests (Phase 4).
 *
 * This exact shape of regression — a mutable column silently dropped by
 * create()/update() because it was missing from TenantUser's #[Fillable]
 * attribute — already happened once for is_active (see .ai/rules/models.md),
 * so it is deliberately proven here rather than assumed.
 */

test('a new tenant user defaults to not being a WhatsApp backup receiver', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $user = TenantUser::factory()->create(['tenant_id' => $tenant->id]);

    expect($user->fresh()->is_whatsapp_backup_receiver)->toBeFalse();
});

test('is_whatsapp_backup_receiver is mass-assignable on create', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $user = TenantUser::factory()->backupReceiver()->create(['tenant_id' => $tenant->id]);

    expect($user->fresh()->is_whatsapp_backup_receiver)->toBeTrue();
});

test('is_whatsapp_backup_receiver is mass-assignable on update and actually persists', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $user = TenantUser::factory()->create(['tenant_id' => $tenant->id]);
    expect($user->fresh()->is_whatsapp_backup_receiver)->toBeFalse();

    $user->update(['is_whatsapp_backup_receiver' => true]);

    expect($user->fresh()->is_whatsapp_backup_receiver)->toBeTrue();

    $user->update(['is_whatsapp_backup_receiver' => false]);

    expect($user->fresh()->is_whatsapp_backup_receiver)->toBeFalse();
});

test('is_whatsapp_backup_receiver is cast to a real boolean, not a raw 0/1', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $user = TenantUser::factory()->backupReceiver()->create(['tenant_id' => $tenant->id]);

    expect($user->fresh()->is_whatsapp_backup_receiver)->toBeBool();
});
