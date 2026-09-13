<?php

use App\Enums\LeadDuplicateLinkStatus;
use App\Enums\LeadSourceType;
use App\Enums\MessageChannel;
use App\Exceptions\LeadMergeException;
use App\Livewire\Tenant\Leads\Index;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadAttachment;
use App\Models\LeadDetailUpdateLog;
use App\Models\LeadDuplicateLink;
use App\Models\LeadNote;
use App\Models\LeadSource;
use App\Models\LeadStatusLog;
use App\Models\Message;
use App\Models\TenantUser;
use App\Services\LeadDuplicateDetectionService;
use App\Services\LeadMergeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('merging two leads reparents all of the loser\'s history onto the survivor', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);

    // Every FK below is explicitly overridden with a real fixture belonging
    // to $tenant — LeadStatusLog/LeadAssignment/LeadDetailUpdateLog's
    // factories default changed_by/assigned_to/assigned_by to a fresh
    // TenantUser::factory(), which in turn spawns its own orphan
    // Tenant::factory() using TenantFactory's own static tenant_code
    // counter — a documented trap (.ai/rules/factories.md) that collides
    // with tenantWithLeadManagement()'s real, DB-count-based tenant_code.
    $note = LeadNote::factory()->create(['lead_id' => $loser->id, 'tenant_user_id' => $clientAdmin->id]);
    $attachment = LeadAttachment::factory()->create(['lead_id' => $loser->id]);
    $statusLog = LeadStatusLog::factory()->create([
        'lead_id' => $loser->id,
        'changed_by' => $clientAdmin->id,
        'old_status_id' => leadStatus('new')->id,
        'new_status_id' => leadStatus('contacted')->id,
    ]);
    $assignment = LeadAssignment::factory()->create([
        'lead_id' => $loser->id,
        'assigned_to' => $clientAdmin->id,
        'assigned_by' => $clientAdmin->id,
    ]);
    $detailLog = LeadDetailUpdateLog::factory()->create(['lead_id' => $loser->id, 'changed_by' => $clientAdmin->id]);

    $leadSource = LeadSource::factory()->create(['tenant_id' => $tenant->id]);
    $conversation = Conversation::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $loser->id,
        'lead_source_id' => $leadSource->id,
    ]);
    $message = Message::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'lead_id' => $loser->id,
    ]);

    $survivorNote = LeadNote::factory()->create(['lead_id' => $survivor->id, 'tenant_user_id' => $clientAdmin->id]);
    $survivorConversation = Conversation::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $survivor->id,
        'lead_source_id' => $leadSource->id,
        'channel' => MessageChannel::Messenger,
    ]);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect($note->fresh()->lead_id)->toBe($survivor->id);
    expect($attachment->fresh()->lead_id)->toBe($survivor->id);
    expect($statusLog->fresh()->lead_id)->toBe($survivor->id);
    expect($assignment->fresh()->lead_id)->toBe($survivor->id);
    expect($detailLog->fresh()->lead_id)->toBe($survivor->id);
    expect($conversation->fresh()->lead_id)->toBe($survivor->id);
    expect($message->fresh()->lead_id)->toBe($survivor->id);

    // The survivor's own pre-existing history is untouched, not replaced.
    expect($survivorNote->fresh()->lead_id)->toBe($survivor->id);
    expect($survivorConversation->fresh()->lead_id)->toBe($survivor->id);

    expect($loser->fresh()->merged_into_lead_id)->toBe($survivor->id);
});

test('merge only overwrites a conflicting field when the loser\'s value is explicitly chosen', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $survivor = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'dup@example.com',
        'company' => 'Old Co',
        'website' => 'https://old.example.com',
    ]);
    $loser = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'dup@example.com',
        'company' => 'New Co',
        'website' => 'https://new.example.com',
    ]);

    $mergeService = app(LeadMergeService::class);

    $conflicts = $mergeService->conflictingFields($survivor, $loser);
    expect($conflicts)->toHaveKeys(['company', 'website']);

    $mergeService->merge($survivor, $loser, $clientAdmin, ['company' => 'loser']);

    $survivor->refresh();
    // Explicitly chosen 'loser' -> overwritten.
    expect($survivor->company)->toBe('New Co');
    // Left unresolved -> safely defaults to keeping the survivor's own value.
    expect($survivor->website)->toBe('https://old.example.com');
});

test('a merge is fully auditable: who, when, and which field values were chosen', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com', 'company' => 'Old Co']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com', 'company' => 'New Co']);

    $link = app(LeadDuplicateDetectionService::class)->detectFor($survivor)->first();

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, ['company' => 'loser'], $link);

    $link->refresh();

    expect($link->status)->toBe(LeadDuplicateLinkStatus::Merged);
    expect($link->resolved_by)->toBe($clientAdmin->id);
    expect($link->resolved_at)->not->toBeNull();
    expect($link->field_resolutions['company'])->toBe([
        'chosen' => 'loser',
        'survivor_value' => 'Old Co',
        'loser_value' => 'New Co',
    ]);

    // The loser itself stays traceable — never deleted, just retired.
    expect(Lead::query()->whereKey($loser->id)->exists())->toBeTrue();
    expect($loser->fresh()->merged_into_lead_id)->toBe($survivor->id);
});

test('merge is rejected across tenants and leaves no side effects', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $clientAdminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();

    $survivor = Lead::factory()->create(['tenant_id' => $tenantA->id]);
    $otherTenantLead = Lead::factory()->create(['tenant_id' => $tenantB->id]);
    $note = LeadNote::factory()->create(['lead_id' => $otherTenantLead->id, 'tenant_user_id' => TenantUser::factory()->create(['tenant_id' => $tenantB->id])]);
    $conversation = Conversation::factory()->create([
        'tenant_id' => $tenantB->id,
        'lead_id' => $otherTenantLead->id,
        'lead_source_id' => LeadSource::factory()->create(['tenant_id' => $tenantB->id])->id,
    ]);

    expect(fn () => app(LeadMergeService::class)->merge($survivor, $otherTenantLead, $clientAdminA, []))
        ->toThrow(LeadMergeException::class);

    expect($otherTenantLead->fresh()->merged_into_lead_id)->toBeNull();
    expect($note->fresh()->lead_id)->toBe($otherTenantLead->id);
    expect($conversation->fresh()->lead_id)->toBe($otherTenantLead->id);
});

test('a lead that has already been merged cannot take part in another merge, and nothing is changed by the attempt', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $thirdLead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    // merge() sets merged_into_lead_id via a raw query builder update (see
    // LeadMergeService), so the in-memory $loser object needs a fresh pull
    // to see it — same reasoning as Lead::markUpdateSeen().
    $loser->refresh();

    // The now-merged-away $loser can no longer be used on either side of a
    // further merge.
    expect(fn () => app(LeadMergeService::class)->merge($thirdLead, $loser, $clientAdmin, []))
        ->toThrow(LeadMergeException::class);

    expect(fn () => app(LeadMergeService::class)->merge($loser, $thirdLead, $clientAdmin, []))
        ->toThrow(LeadMergeException::class);

    expect($thirdLead->fresh()->merged_into_lead_id)->toBeNull();
});

test('a failure partway through a merge rolls back every change already made in that transaction', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'company' => 'Old Co']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'company' => 'New Co']);
    $note = LeadNote::factory()->create(['lead_id' => $loser->id, 'tenant_user_id' => $clientAdmin->id]);
    $conversation = Conversation::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $loser->id,
        'lead_source_id' => LeadSource::factory()->create(['tenant_id' => $tenant->id])->id,
    ]);

    // A TenantUser that was built but never actually saved: LeadDuplicateLink
    // .resolved_by's foreign key to tenant_users then fails on the merge's
    // very last write — AFTER the note above has already been reparented
    // and $survivor->company has already been updated earlier in the SAME
    // transaction — proving the whole operation is atomic, not just its
    // individual steps.
    $neverPersisted = TenantUser::factory()->make(['id' => (string) Str::ulid(), 'tenant_id' => $tenant->id]);

    expect(fn () => app(LeadMergeService::class)->merge($survivor, $loser, $neverPersisted, ['company' => 'loser']))
        ->toThrow(Exception::class);

    // Nothing persisted: the note and conversation were never actually
    // reparented, the loser was never marked merged, and the survivor's
    // company is untouched.
    expect($note->fresh()->lead_id)->toBe($loser->id);
    expect($conversation->fresh()->lead_id)->toBe($loser->id);
    expect($loser->fresh()->merged_into_lead_id)->toBeNull();
    expect($survivor->fresh()->company)->toBe('Old Co');
});

test('a merged-away lead is excluded from the leads index but a direct link to it redirects to the survivor', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Survivor Lead']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Loser Lead']);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->assertSee('Survivor Lead')
        ->assertDontSee('Loser Lead');

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $loser->fresh()])
        ->assertRedirect(route('tenant-leads.show', $survivor));
});

test('a client admin can merge a detected duplicate from the lead detail page', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);

    $link = app(LeadDuplicateDetectionService::class)->detectFor($survivor)->first();

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $survivor])
        ->call('completeInitialLoad')
        ->assertSee($loser->identifier)
        ->call('openMergeModal', $link->id)
        ->assertSet('showMergeModal', true)
        ->call('confirmMerge')
        ->assertDispatched('toast-show');

    expect($loser->fresh()->merged_into_lead_id)->toBe($survivor->id);
});

test('a client user without admin rights cannot merge or dismiss a possible duplicate', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com', 'assigned_to' => $rep->id]);
    $other = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);

    $link = app(LeadDuplicateDetectionService::class)->detectFor($lead)->first();

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('openMergeModal', $link->id)
        ->assertSet('showMergeModal', false)
        ->call('dismissDuplicate', $link->id)
        ->assertDispatched('toast-show');

    expect($link->fresh()->status)->toBe(LeadDuplicateLinkStatus::Pending);
    expect($other->fresh()->merged_into_lead_id)->toBeNull();
});

test('dismissing a possible duplicate from the lead detail page removes it from the banner and is not re-flagged', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $other = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad');

    $link = app(LeadDuplicateDetectionService::class)->pendingLinksFor($lead)->first();

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('dismissDuplicate', $link->id)
        ->assertDispatched('toast-show');

    expect($link->fresh()->status)->toBe(LeadDuplicateLinkStatus::Dismissed);
    expect(app(LeadDuplicateDetectionService::class)->detectFor($other))->toHaveCount(0);
});

test('a loser\'s multiple conversations across different lead sources all reparent onto the survivor without deduplication', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $survivorSource = LeadSource::factory()->create(['tenant_id' => $tenant->id]);
    $loserSourceA = LeadSource::factory()->create(['tenant_id' => $tenant->id]);
    $loserSourceB = LeadSource::factory()->create(['tenant_id' => $tenant->id]);

    $survivorConversation = Conversation::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $survivor->id,
        'lead_source_id' => $survivorSource->id,
        'external_thread_id' => 'survivor-thread',
    ]);
    $loserConversationA = Conversation::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $loser->id,
        'lead_source_id' => $loserSourceA->id,
        'external_thread_id' => 'loser-thread-a',
    ]);
    $loserConversationB = Conversation::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $loser->id,
        'lead_source_id' => $loserSourceB->id,
        'external_thread_id' => 'loser-thread-b',
    ]);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect($survivorConversation->fresh()->lead_id)->toBe($survivor->id);
    expect($loserConversationA->fresh()->lead_id)->toBe($survivor->id);
    expect($loserConversationB->fresh()->lead_id)->toBe($survivor->id);

    // Three distinct rows, each keeping its own lead_source_id/thread
    // identity — none collapsed or deduplicated into another.
    $survivorConversations = $survivor->fresh()->conversations;
    expect($survivorConversations)->toHaveCount(3);
    expect($survivorConversations->pluck('lead_source_id')->unique())->toHaveCount(3);
});

test('messageTimeline surfaces the loser\'s reparented normalized messages while the survivor\'s own legacy history stays untouched', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $whatsappSource = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp]);

    $survivor = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $whatsappSource->id,
        'source_data' => [
            'messages' => [
                [
                    'type' => 'text',
                    'text' => ['body' => 'Legacy survivor message'],
                    'timestamp' => Carbon::parse('2026-01-01 09:00:00')->timestamp,
                ],
            ],
        ],
    ]);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $whatsappSource->id]);

    $conversation = Conversation::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $loser->id,
        'lead_source_id' => $whatsappSource->id,
    ]);
    Message::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'lead_id' => $loser->id,
        'body' => 'Hello from loser',
        'created_at' => Carbon::parse('2026-01-02 10:00:00'),
    ]);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    $texts = collect($survivor->fresh()->messageTimeline())->pluck('text')->all();

    // The loser's normalized message is now visible on the survivor...
    expect($texts)->toContain('Hello from loser');
    // ...and the survivor's own pre-existing legacy source_data entry is
    // completely untouched, not replaced or hidden by the reparented row.
    expect($texts)->toContain('Legacy survivor message');
});
