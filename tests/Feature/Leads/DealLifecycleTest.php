<?php

use App\Enums\DealStatus;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Deal;
use App\Models\DealStatusLog;
use App\Models\Lead;
use App\Models\TenantUser;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('a deal moves from open to won and records a status log entry', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $deal = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeDealStatus', $deal->id, 'won')
        ->assertHasNoErrors();

    $deal->refresh();
    expect($deal->status)->toBe(DealStatus::Won);
    expect($deal->actual_close_date?->toDateString())->toBe(now()->toDateString());

    $log = DealStatusLog::where('deal_id', $deal->id)->latest('created_at')->firstOrFail();
    expect($log->old_status)->toBe(DealStatus::Open);
    expect($log->new_status)->toBe(DealStatus::Won);
    expect($log->changed_by)->toBe($clientAdmin->id);
});

test('a deal moves from open to lost and records a status log entry', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $deal = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeDealStatus', $deal->id, 'lost');

    $deal->refresh();
    expect($deal->status)->toBe(DealStatus::Lost);
    expect($deal->actual_close_date)->not->toBeNull();
    expect(DealStatusLog::where('deal_id', $deal->id)->where('new_status', DealStatus::Lost->value)->exists())->toBeTrue();
});

test('a failure partway through changeDealStatus() rolls back both the deal update and its status log', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $deal = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    // A TenantUser with the identical tenant_id/role_id/user_type as the
    // real, already-authorized $clientAdmin, but never actually persisted:
    // Gate::denies('update', $lead) passes, but DealStatusLog.changed_by's
    // foreign key to tenant_users then fails on the write immediately after
    // $deal->update() — mirroring the same technique used for updateStatus()
    // and the established pattern in LeadMergeTest.php/LeadAssignmentTest.php.
    $neverPersisted = TenantUser::factory()->make([
        'id' => (string) Str::ulid(),
        'tenant_id' => $clientAdmin->tenant_id,
        'role_id' => $clientAdmin->role_id,
        'user_type' => $clientAdmin->user_type,
    ]);

    expect(fn () => Livewire::actingAs($neverPersisted)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeDealStatus', $deal->id, 'won'))
        ->toThrow(Exception::class);

    // Nothing persisted: the deal's status/actual_close_date change made by
    // $deal->update() inside the same transaction was rolled back, and no
    // DealStatusLog row exists.
    $deal->refresh();
    expect($deal->status)->toBe(DealStatus::Open);
    expect($deal->actual_close_date)->toBeNull();
    expect(DealStatusLog::where('deal_id', $deal->id)->exists())->toBeFalse();
});

test('a won deal can be reopened, clearing its actual close date', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $deal = Deal::factory()->won()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeDealStatus', $deal->id, 'open')
        ->assertHasNoErrors();

    $deal->refresh();
    expect($deal->status)->toBe(DealStatus::Open);
    expect($deal->actual_close_date)->toBeNull();

    $log = DealStatusLog::where('deal_id', $deal->id)->latest('created_at')->firstOrFail();
    expect($log->old_status)->toBe(DealStatus::Won);
    expect($log->new_status)->toBe(DealStatus::Open);
});

test('a lost deal can be reopened, clearing its actual close date', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $deal = Deal::factory()->lost()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeDealStatus', $deal->id, 'open');

    $deal->refresh();
    expect($deal->status)->toBe(DealStatus::Open);
    expect($deal->actual_close_date)->toBeNull();
});

test('a won deal cannot transition directly to lost', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $deal = Deal::factory()->won()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeDealStatus', $deal->id, 'lost');

    $deal->refresh();
    expect($deal->status)->toBe(DealStatus::Won);
    expect(DealStatusLog::where('deal_id', $deal->id)->where('new_status', DealStatus::Lost->value)->exists())->toBeFalse();
    // The rejected transition must not touch actual_close_date either —
    // it stays exactly what the Won factory state originally set it to.
    expect($deal->actual_close_date?->toDateString())->toBe(now()->toDateString());
});

test('a lost deal cannot transition directly to won', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $deal = Deal::factory()->lost()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeDealStatus', $deal->id, 'won');

    $deal->refresh();
    expect($deal->status)->toBe(DealStatus::Lost);
    expect(DealStatusLog::where('deal_id', $deal->id)->where('new_status', DealStatus::Won->value)->exists())->toBeFalse();
    expect($deal->actual_close_date?->toDateString())->toBe(now()->toDateString());
});

test('no status log is written when a transition is rejected', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $deal = Deal::factory()->won()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeDealStatus', $deal->id, 'lost');

    expect(DealStatusLog::where('deal_id', $deal->id)->count())->toBe(0);
});

test('a deal status change never touches the lead\'s own status', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);
    $deal = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeDealStatus', $deal->id, 'won');

    expect($lead->fresh()->status_id)->toBe(leadStatus('new')->id);
});

test('deal status transitions appear in the lead\'s activity feed', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $deal = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'name' => 'Renewal Deal']);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeDealStatus', $deal->id, 'won');

    $event = collect($component->instance()->activity())->firstWhere('type', 'deal_status_change');
    expect($event)->not->toBeNull();
    expect($event['deal_name'])->toBe('Renewal Deal');
    expect($event['new_status'])->toBe('Won');
});

test('an assigned client user without update permission cannot change a deal\'s status', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);
    $deal = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeDealStatus', $deal->id, 'won');

    expect($deal->fresh()->status)->toBe(DealStatus::Open);
});
