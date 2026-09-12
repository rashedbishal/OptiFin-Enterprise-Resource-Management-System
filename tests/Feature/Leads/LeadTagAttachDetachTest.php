<?php

use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadDetailUpdateLog;
use App\Models\LeadTag;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('a client admin can attach and detach a tag on a lead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tag = LeadTag::factory()->create(['tenant_id' => $tenant->id, 'name' => 'VIP']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->set('selectedTagId', $tag->id)
        ->call('attachTag')
        ->assertHasNoErrors();

    expect($lead->fresh()->tags->pluck('id')->all())->toBe([$tag->id]);
    expect(DB::table('lead_lead_tag')->where('lead_id', $lead->id)->where('lead_tag_id', $tag->id)->value('tagged_by'))
        ->toBe($clientAdmin->id);

    $component->call('detachTag', $tag->id);

    expect($lead->fresh()->tags)->toHaveCount(0);
});

test('attaching an already-attached tag is idempotent and never duplicates the pivot row', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tag = LeadTag::factory()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $lead->tags()->attach($tag->id, ['tagged_by' => $clientAdmin->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->set('selectedTagId', $tag->id)
        ->call('attachTag');

    expect(DB::table('lead_lead_tag')->where('lead_id', $lead->id)->where('lead_tag_id', $tag->id)->count())->toBe(1);
});

test('a tenant\'s own tag catalog only ever offers its own leads its own tags to attach', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $adminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();

    $foreignTag = LeadTag::factory()->create(['tenant_id' => $tenantB->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenantA->id]);

    Livewire::actingAs($adminA)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->set('selectedTagId', $foreignTag->id)
        ->call('attachTag')
        ->assertHasErrors('selectedTagId');

    expect($lead->fresh()->tags)->toHaveCount(0);
});

test('an assigned client user with update permission can attach and detach tags on their own lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $tag = LeadTag::factory()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->set('selectedTagId', $tag->id)
        ->call('attachTag')
        ->assertHasNoErrors();

    expect($lead->fresh()->tags->pluck('id')->all())->toBe([$tag->id]);
});

test('an assigned client user without the update permission cannot attach a tag', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $tag = LeadTag::factory()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->set('selectedTagId', $tag->id)
        ->call('attachTag');

    expect($lead->fresh()->tags)->toHaveCount(0);
});

test('attaching a tag records a tag_added activity event, detaching records tag_removed', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tag = LeadTag::factory()->create(['tenant_id' => $tenant->id, 'name' => 'VIP']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->set('selectedTagId', $tag->id)
        ->call('attachTag');

    $addedLog = LeadDetailUpdateLog::where('lead_id', $lead->id)->where('action', 'tag_added')->firstOrFail();
    expect($addedLog->changed_fields)->toBe(['tag' => 'VIP']);
    expect($addedLog->changed_by)->toBe($clientAdmin->id);

    $addedEvent = collect($component->instance()->activity())->firstWhere('type', 'tag_added');
    expect($addedEvent)->not->toBeNull();
    expect($addedEvent['fields']['tag'])->toBe('VIP');

    $component->call('detachTag', $tag->id);

    $removedLog = LeadDetailUpdateLog::where('lead_id', $lead->id)->where('action', 'tag_removed')->firstOrFail();
    expect($removedLog->changed_fields)->toBe(['tag' => 'VIP']);

    $removedEvent = collect($component->instance()->activity())->firstWhere('type', 'tag_removed');
    expect($removedEvent)->not->toBeNull();
    expect($removedEvent['fields']['tag'])->toBe('VIP');
});

test('detaching a tag id that is not actually attached to the lead is a silent no-op', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tag = LeadTag::factory()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('detachTag', $tag->id);

    expect(LeadDetailUpdateLog::where('lead_id', $lead->id)->where('action', 'tag_removed')->exists())->toBeFalse();
});
