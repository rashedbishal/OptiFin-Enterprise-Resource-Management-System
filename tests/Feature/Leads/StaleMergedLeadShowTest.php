<?php

use App\Enums\LeadSourceType;
use App\Events\LeadUpdated;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadCustomFieldDefinition;
use App\Models\LeadDeletionAudit;
use App\Models\LeadDuplicateLink;
use App\Models\LeadSource;
use App\Models\LeadTag;
use App\Models\Task;
use App\Services\LeadMergeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/*
 * UX-1 — a Show component mounted BEFORE its lead was merged away must refuse
 * every mutation afterwards instead of writing to the retired loser row.
 *
 * Every scenario mounts Show on the lead first, merges it (LeadMergeService,
 * the same call a Client Admin's merge makes), and only then drives the OLD
 * component — exactly what a second browser tab does. Rows the merge would
 * have reparented (deals, tasks, links) are seeded on the lead AFTER the
 * merge, so each action would genuinely have something to change if the guard
 * were missing; the non-merged control test runs the identical action list
 * against a normal lead to prove that.
 */

const STALE_MERGED_MESSAGE = 'This lead has been merged and can no longer be changed.';

/**
 * @param  array<int, string>  $repPermissions
 * @return array<string, mixed>
 */
function staleMergedScenario(bool $merged, string $actor = 'admin', bool $panel = false, array $repPermissions = ['view', 'update']): array
{
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, $repPermissions);
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    // Unqualified: Show::confirmDelete()/delete() now also require this
    // (see their own docblocks) — set here so the confirmDelete row of
    // the data-driven test below exercises the merge-protection guard
    // this file is about, both in the merged case (still refused, for
    // the expected merge reason) and the non-merged control case (must
    // actually succeed in opening the modal, which it wouldn't if this
    // lead were left at its default non-Unqualified status).
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => $rep->id, 'assigned_at' => now(), 'status_id' => leadStatus('unqualified')->id]);
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id]);

    $component = Livewire::actingAs($actor === 'rep' ? $rep : $admin)
        ->test(Show::class, ['lead' => $lead, 'panel' => $panel])
        ->call('completeInitialLoad');

    if ($merged) {
        app(LeadMergeService::class)->merge($survivor->fresh(), $lead->fresh(), $admin, []);
    }

    $deal = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'assigned_to' => $rep->id]);
    $attachedTag = LeadTag::factory()->create(['tenant_id' => $tenant->id]);
    $attachableTag = LeadTag::factory()->create(['tenant_id' => $tenant->id]);
    $lead->tags()->attach($attachedTag->id, ['tagged_by' => $admin->id]);
    DB::table('leads')->where('id', $lead->id)->update(['meeting_date' => today()->toDateString(), 'next_follow_up_at' => today()->toDateString()]);

    $other = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $link = LeadDuplicateLink::factory()->create([
        'tenant_id' => $tenant->id,
        ...array_combine(['lead_id', 'duplicate_lead_id'], LeadDuplicateLink::pairKeys($lead, $other)),
    ]);

    return compact('tenant', 'admin', 'rep', 'survivor', 'lead', 'definition', 'deal', 'task', 'attachedTag', 'attachableTag', 'link', 'component');
}

/**
 * Every row of every table the mutations touch, for both leads of the merge,
 * plus the global counters a stray side effect would move.
 *
 * @param  array<string, mixed>  $scenario
 * @return array<string, mixed>
 */
function staleMergedFingerprint(array $scenario): array
{
    $ids = [$scenario['lead']->id, $scenario['survivor']->id];
    $rows = fn ($query): array => $query->get()->map(fn ($row): array => (array) $row)->all();

    $fingerprint = ['leads' => $rows(DB::table('leads')->whereIn('id', $ids)->orderBy('id'))];

    foreach (['lead_notes', 'lead_lead_tag', 'tasks', 'deals', 'lead_custom_field_values', 'lead_consents', 'lead_consent_events', 'lead_status_logs', 'lead_assignments', 'lead_detail_update_logs', 'lead_score_logs'] as $table) {
        $fingerprint[$table] = $rows(DB::table($table)->whereIn('lead_id', $ids));
    }

    $fingerprint['lead_duplicate_links'] = $rows(DB::table('lead_duplicate_links'));

    foreach (['deal_status_logs', 'task_status_logs', 'notifications', 'lead_deletion_audits'] as $table) {
        $fingerprint[$table] = DB::table($table)->count();
    }

    return $fingerprint;
}

/**
 * @return array<int, array{0: ?string, 1: ?string}> [variant, text] of every toast the last request dispatched
 */
function staleMergedToasts($component): array
{
    return collect($component->effects['dispatches'] ?? [])
        ->where('name', 'toast-show')
        ->map(fn (array $dispatch): array => [$dispatch['params']['dataset']['variant'] ?? null, $dispatch['params']['slots']['text'] ?? null])
        ->values()
        ->all();
}

/**
 * Each row: [action against the OLD component, success text the same action
 * produces on a normal, non-merged lead]. Every write entry point guarded by
 * UX-1 appears here; a row that sets state first (a draft, a pending id) does
 * so with plain property sets, which never write to the database.
 *
 * @return array<string, array{0: Closure, 1: string}>
 */
function staleMergedMutations(): array
{
    return [
        'updateCustomFields' => [fn ($c, array $x) => $c->set('customFieldValues.'.$x['definition']->id, 'value')->call('updateCustomFields'), 'Lead updated.'],
        'updateStatus' => [fn ($c, array $x) => $c->set('status', leadStatus('contacted')->id)->call('updateStatus'), 'Lead updated.'],
        'updateContact' => [fn ($c, array $x) => $c->set('leadName', 'Renamed Person')->set('leadPhone', '+15551234567')->set('leadEmail', 'renamed@example.com')->call('updateContact'), 'Lead updated.'],
        'updateCompany' => [fn ($c, array $x) => $c->set('leadCompany', 'Acme Co')->call('updateCompany'), 'Lead updated.'],
        'updateDeal' => [fn ($c, array $x) => $c->set('leadServiceInterest', 'Consulting')->call('updateDeal'), 'Lead updated.'],
        'assign' => [fn ($c, array $x) => $c->set('assigneeId', $x['admin']->id)->call('assign'), 'Lead assigned to'],
        'cancelMeeting' => [fn ($c, array $x) => $c->call('cancelMeeting'), 'Meeting cancelled.'],
        'cancelMeetingConfirmed' => [fn ($c, array $x) => $c->call('cancelMeetingConfirmed'), 'Meeting cancelled.'],
        'markMeetingDone' => [fn ($c, array $x) => $c->call('markMeetingDone'), 'Meeting marked as done.'],
        'cancelFollowUp' => [fn ($c, array $x) => $c->call('cancelFollowUp'), 'Follow-up cancelled.'],
        'cancelFollowUpConfirmed' => [fn ($c, array $x) => $c->call('cancelFollowUpConfirmed'), 'Follow-up cancelled.'],
        'markFollowUpDone' => [fn ($c, array $x) => $c->call('markFollowUpDone'), 'Follow-up marked as done.'],
        'addNote' => [fn ($c, array $x) => $c->set('newNote', 'A note')->call('addNote'), 'Note added.'],
        'attachTag' => [fn ($c, array $x) => $c->set('selectedTagId', $x['attachableTag']->id)->call('attachTag'), 'Tag added.'],
        'detachTag' => [fn ($c, array $x) => $c->call('detachTag', $x['attachedTag']->id), 'Tag removed.'],
        'grantConsent' => [fn ($c, array $x) => $c->call('grantConsent', 'privacy'), 'consent granted.'],
        'withdrawConsent' => [fn ($c, array $x) => $c->call('withdrawConsent', 'privacy'), 'consent withdrawn.'],
        'saveDeal' => [fn ($c, array $x) => $c->set('dealName', 'A deal')->call('saveDeal'), 'Deal saved.'],
        'changeDealStatus' => [fn ($c, array $x) => $c->call('changeDealStatus', $x['deal']->id, 'won'), 'Deal status updated.'],
        'deleteDeal' => [fn ($c, array $x) => $c->call('deleteDeal', $x['deal']->id), 'Deal deleted.'],
        'deleteDealConfirmed' => [fn ($c, array $x) => $c->set('confirmingDeleteDealId', $x['deal']->id)->call('deleteDealConfirmed'), 'Deal deleted.'],
        'saveTask' => [fn ($c, array $x) => $c->set('taskTitle', 'A task')->set('taskPriority', 'medium')->call('saveTask'), 'Task saved.'],
        'changeTaskStatus' => [fn ($c, array $x) => $c->call('changeTaskStatus', $x['task']->id, 'completed'), 'Task status updated.'],
        'deleteTask' => [fn ($c, array $x) => $c->call('deleteTask', $x['task']->id), 'Task deleted.'],
        'deleteTaskConfirmed' => [fn ($c, array $x) => $c->set('confirmingDeleteTaskId', $x['task']->id)->call('deleteTaskConfirmed'), 'Task deleted.'],
        'recalculateScore' => [fn ($c, array $x) => $c->call('recalculateScore'), 'Lead score recalculated.'],
        'dismissDuplicate' => [fn ($c, array $x) => $c->call('dismissDuplicate', $x['link']->id), 'Marked as not a duplicate.'],
        'confirmMerge' => [fn ($c, array $x) => $c->call('openMergeModal', $x['link']->id)->call('confirmMerge'), 'Lead merged.'],
    ];
}

test('a stale Show refuses the mutation on a lead that was merged away after it was mounted', function (Closure $act, string $successText) {
    Event::fake([LeadUpdated::class]);
    $scenario = staleMergedScenario(merged: true);
    $before = staleMergedFingerprint($scenario);

    $component = $act($scenario['component'], $scenario);

    expect(staleMergedToasts($component))->toBe([['danger', STALE_MERGED_MESSAGE]]);
    expect(staleMergedFingerprint($scenario))->toBe($before);
    Event::assertNotDispatched(LeadUpdated::class);
})->with(staleMergedMutations());

test('the same action list still succeeds on a normal, non-merged lead', function (Closure $act, string $successText) {
    $scenario = staleMergedScenario(merged: false);

    $component = $act($scenario['component'], $scenario);

    $toasts = staleMergedToasts($component);

    expect(collect($toasts)->contains(fn (array $toast): bool => $toast[0] === 'success' && str_contains((string) $toast[1], $successText)))->toBeTrue();
    expect(collect($toasts)->pluck(1))->not->toContain(STALE_MERGED_MESSAGE);
})->with(staleMergedMutations());

test('a stale panel refuses the mutation for a non-admin assigned rep, without telling the parent board anything', function (Closure $act, string $successText) {
    $scenario = staleMergedScenario(merged: true, actor: 'rep', panel: true);
    $before = staleMergedFingerprint($scenario);

    expect($scenario['component']->get('panel'))->toBeTrue();

    $component = $act($scenario['component'], $scenario);

    expect(staleMergedToasts($component))->toBe([['danger', STALE_MERGED_MESSAGE]]);
    expect(collect($component->effects['dispatches'] ?? [])->pluck('name')->unique()->values()->all())->toBe(['toast-show']);
    expect(staleMergedFingerprint($scenario))->toBe($before);
})->with(array_intersect_key(staleMergedMutations(), array_flip(['updateStatus', 'addNote', 'saveTask', 'withdrawConsent', 'attachTag', 'updateCustomFields'])));

test('a panel mutation on a normal lead still dispatches lead-panel-updated, so its absence above is meaningful', function () {
    $scenario = staleMergedScenario(merged: false, actor: 'rep', panel: true);

    $component = $scenario['component']->set('status', leadStatus('contacted')->id)->call('updateStatus');

    expect(collect($component->effects['dispatches'] ?? [])->pluck('name')->all())->toContain('lead-panel-updated');
});

test('a stale delete of a merged lead is refused before any destructive work', function (bool $panel, Closure $act) {
    $scenario = staleMergedScenario(merged: true, panel: $panel);
    $before = staleMergedFingerprint($scenario);
    $mergeLinkIds = LeadDuplicateLink::query()->orderBy('id')->pluck('id')->all();

    $component = $act($scenario['component']);

    expect(staleMergedToasts($component))->toBe([['danger', STALE_MERGED_MESSAGE]]);
    expect($component->effects['redirect'] ?? null)->toBeNull();
    expect(collect($component->effects['dispatches'] ?? [])->pluck('name')->all())->not->toContain('lead-panel-deleted');
    expect(Lead::whereKey($scenario['lead']->id)->value('merged_into_lead_id'))->toBe($scenario['survivor']->id);
    expect(LeadDeletionAudit::count())->toBe(0);
    expect(LeadDuplicateLink::query()->orderBy('id')->pluck('id')->all())->toBe($mergeLinkIds);
    expect(staleMergedFingerprint($scenario))->toBe($before);
})->with([
    'page: delete' => [false, fn ($c) => $c->set('deletePassword', 'password')->call('delete')],
    'page: deleteConfirmed with the right password' => [false, fn ($c) => $c->set('deletePassword', 'password')->call('deleteConfirmed')],
    'panel: delete' => [true, fn ($c) => $c->set('deletePassword', 'password')->call('delete')],
    'panel: deleteConfirmed with the right password' => [true, fn ($c) => $c->set('deletePassword', 'password')->call('deleteConfirmed')],
]);

test('deleting a normal, non-merged lead through the same Show still works', function () {
    $scenario = staleMergedScenario(merged: false);

    $component = $scenario['component']->set('deletePassword', 'password')->call('delete');

    expect($component->effects['redirect'] ?? null)->not->toBeNull();
    expect(Lead::whereKey($scenario['lead']->id)->exists())->toBeFalse();
    expect(LeadDeletionAudit::count())->toBe(1);
});

test('the six form and modal openers refuse on a merged lead and open normally otherwise', function (string $flag, Closure $open) {
    $merged = staleMergedScenario(merged: true);
    $refused = $open($merged['component'], $merged);

    expect(staleMergedToasts($refused))->toBe([['danger', STALE_MERGED_MESSAGE]]);
    expect($refused->get($flag))->toBeFalse();

    $normal = staleMergedScenario(merged: false);
    $opened = $open($normal['component'], $normal);

    expect(staleMergedToasts($opened))->toBe([]);
    expect($opened->get($flag))->toBeTrue();
})->with([
    'createDeal' => ['showDealModal', fn ($c, array $x) => $c->call('createDeal')],
    'editDeal' => ['showDealModal', fn ($c, array $x) => $c->call('editDeal', $x['deal']->id)],
    'createTask' => ['showTaskModal', fn ($c, array $x) => $c->call('createTask')],
    'editTask' => ['showTaskModal', fn ($c, array $x) => $c->call('editTask', $x['task']->id)],
    'openMergeModal' => ['showMergeModal', fn ($c, array $x) => $c->call('openMergeModal', $x['link']->id)],
    'confirmDelete' => ['showDeleteModal', fn ($c, array $x) => $c->call('confirmDelete')],
]);

test('a refused save keeps the open modal, the edit form and the unsaved draft exactly as they were', function () {
    $scenario = staleMergedScenario(merged: true);

    $deal = $scenario['component']->set('showDealModal', true)->set('dealName', 'Draft deal')->call('saveDeal');
    expect($deal->get('showDealModal'))->toBeTrue();
    expect($deal->get('dealName'))->toBe('Draft deal');

    $task = $deal->set('showTaskModal', true)->set('taskTitle', 'Draft task')->set('taskPriority', 'medium')->call('saveTask');
    expect($task->get('showTaskModal'))->toBeTrue();
    expect($task->get('taskTitle'))->toBe('Draft task');

    $contact = $task->call('startEditingContact')->set('leadName', 'Draft Name')->call('updateContact');
    expect($contact->get('editingContact'))->toBeTrue();
    expect($contact->get('leadName'))->toBe('Draft Name');
});

test('updateStatusAndAssignment() shows exactly one merged-lead refusal for an actor who can both update and assign', function () {
    $scenario = staleMergedScenario(merged: true);
    $before = staleMergedFingerprint($scenario);

    $component = $scenario['component']
        ->set('status', leadStatus('contacted')->id)
        ->set('assigneeId', $scenario['admin']->id)
        ->call('updateStatusAndAssignment');

    expect(staleMergedToasts($component))->toBe([['danger', STALE_MERGED_MESSAGE]]);
    expect(staleMergedFingerprint($scenario))->toBe($before);
});

test('updateStatusAndAssignment() shows exactly one merged-lead refusal for an actor who can only update', function () {
    $scenario = staleMergedScenario(merged: true, actor: 'rep');
    $before = staleMergedFingerprint($scenario);

    $component = $scenario['component']->set('status', leadStatus('contacted')->id)->call('updateStatusAndAssignment');

    expect(staleMergedToasts($component))->toBe([['danger', STALE_MERGED_MESSAGE]]);
    expect(staleMergedFingerprint($scenario))->toBe($before);
});

test('updateStatusAndAssignment() still applies both halves on a normal lead', function () {
    $scenario = staleMergedScenario(merged: false);

    $component = $scenario['component']
        ->set('status', leadStatus('contacted')->id)
        ->set('assigneeId', $scenario['admin']->id)
        ->call('updateStatusAndAssignment');

    expect(collect(staleMergedToasts($component))->pluck(1)->all())->toContain('Lead updated.');
    expect($scenario['lead']->fresh()->assigned_to)->toBe($scenario['admin']->id);
    expect($scenario['lead']->fresh()->status_id)->toBe(leadStatus('contacted')->id);
});

test('an actor who may not update keeps the existing permission-denial behavior on a merged lead, not the merged message', function () {
    $scenario = staleMergedScenario(merged: true, actor: 'rep', repPermissions: ['view']);
    $before = staleMergedFingerprint($scenario);

    $note = $scenario['component']->set('newNote', 'A note')->call('addNote');
    expect(staleMergedToasts($note))->toBe([['danger', "You don't have permission to update this lead."]]);

    $assign = $scenario['component']->set('assigneeId', $scenario['admin']->id)->call('assign');
    expect(staleMergedToasts($assign))->toBe([['danger', "You don't have permission to assign this lead."]]);

    $wrapper = $scenario['component']->set('status', leadStatus('contacted')->id)->call('updateStatusAndAssignment');
    expect(staleMergedToasts($wrapper))->toBe([]);

    expect(staleMergedFingerprint($scenario))->toBe($before);
});
