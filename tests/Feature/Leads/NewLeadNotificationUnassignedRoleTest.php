<?php

use App\Enums\LeadSourceType;
use App\Events\LeadReceived;
use App\Listeners\SendNewLeadNotification;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Notifications\NewLeadNotification;
use Illuminate\Support\Facades\Notification;

/*
 * SendNewLeadNotification always notified every active Client Admin; it now
 * also notifies every active Client User whose role has been granted
 * "receive unassigned leads" access to the lead's specific source (see
 * LeadSource::unassignedRoles()'s own docblock) — the same real-time bell
 * a Client Admin already gets, extended to the group this feature adds
 * visibility for, and only ever for a lead that's still genuinely
 * unassigned when LeadReceived fires.
 */

function grantSource($tenant, LeadSourceType $type = LeadSourceType::Whatsapp): LeadSource
{
    return LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => $type]);
}

test('a qualifying Client User is notified of a new unassigned lead from the granted source', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = grantSource($tenant);
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $source->unassignedRoles()->attach($rep->role_id);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => null]);

    Notification::fake();

    app(SendNewLeadNotification::class)->handle(new LeadReceived($lead));

    Notification::assertSentTo($rep, NewLeadNotification::class);
});

test('a non-qualifying Client User is never notified', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = grantSource($tenant);
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => null]);

    Notification::fake();

    app(SendNewLeadNotification::class)->handle(new LeadReceived($lead));

    Notification::assertNotSentTo($rep, NewLeadNotification::class);
});

test('a qualifying Client User is never notified for a lead from a DIFFERENT, non-granted source', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $grantedSource = grantSource($tenant);
    $otherSource = grantSource($tenant);
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $grantedSource->unassignedRoles()->attach($rep->role_id);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $otherSource->id, 'assigned_to' => null]);

    Notification::fake();

    app(SendNewLeadNotification::class)->handle(new LeadReceived($lead));

    Notification::assertNotSentTo($rep, NewLeadNotification::class);
});

test('a qualifying Client User is never notified when the lead already arrived pre-assigned', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = grantSource($tenant);
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $source->unassignedRoles()->attach($rep->role_id);
    $assignee = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => $assignee->id]);

    Notification::fake();

    app(SendNewLeadNotification::class)->handle(new LeadReceived($lead));

    Notification::assertNotSentTo($rep, NewLeadNotification::class);
});

test('Client Admins are still notified too — unchanged baseline, additive only', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $source = grantSource($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => null]);

    Notification::fake();

    app(SendNewLeadNotification::class)->handle(new LeadReceived($lead));

    Notification::assertSentTo($admin, NewLeadNotification::class);
});

test('an inactive qualifying Client User is never notified', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = grantSource($tenant);
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $source->unassignedRoles()->attach($rep->role_id);
    $rep->update(['is_active' => false]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => null]);

    Notification::fake();

    app(SendNewLeadNotification::class)->handle(new LeadReceived($lead));

    Notification::assertNotSentTo($rep, NewLeadNotification::class);
});

test('a qualifying user from another tenant is never notified', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = grantSource($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => null]);

    ['tenant' => $otherTenant] = tenantWithLeadManagement();
    $foreignSource = grantSource($otherTenant);
    $foreignRep = clientUserWithLeadPermission($otherTenant, ['view', 'update']);
    $foreignSource->unassignedRoles()->attach($foreignRep->role_id);

    Notification::fake();

    app(SendNewLeadNotification::class)->handle(new LeadReceived($lead));

    Notification::assertNotSentTo($foreignRep, NewLeadNotification::class);
});

test('multiple qualifying Client Users for the same source are all notified', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = grantSource($tenant);
    $repA = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $repB = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $source->unassignedRoles()->attach([$repA->role_id, $repB->role_id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => null]);

    Notification::fake();

    app(SendNewLeadNotification::class)->handle(new LeadReceived($lead));

    Notification::assertSentTo($repA, NewLeadNotification::class);
    Notification::assertSentTo($repB, NewLeadNotification::class);
});
