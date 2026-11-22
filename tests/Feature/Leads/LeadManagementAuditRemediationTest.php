<?php

use App\Enums\AutoDistributionMethod;
use App\Enums\LeadDistributionMode;
use App\Enums\LeadDuplicateLinkStatus;
use App\Enums\LeadScoringField;
use App\Enums\LeadScoringOperator;
use App\Enums\LeadSourceType;
use App\Enums\MessageChannel;
use App\Enums\TaskStatus;
use App\Events\LeadReceived;
use App\Events\LeadUpdated;
use App\Exceptions\LeadMergeException;
use App\Jobs\SendOutboundMessage;
use App\Listeners\AutoAssignLead;
use App\Livewire\Tenant\Leads\Index;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadDuplicateLink;
use App\Models\LeadScoringRule;
use App\Models\LeadSource;
use App\Models\Message;
use App\Models\Task;
use App\Services\LeadAssignmentService;
use App\Services\LeadMergeService;
use App\Services\MessageSenderFactory;
use App\Services\OutboundEligibilityService;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Regression tests for the 2026-11-22 Lead Management audit remediation.
 */

/**
 * Route every broadcast through a broadcaster that runs $onBroadcast.
 */
function useTestBroadcaster(Closure $onBroadcast): void
{
    Broadcast::extend('audit-test', fn () => new class($onBroadcast) extends Broadcaster
    {
        public function __construct(private Closure $onBroadcast) {}

        public function auth($request): mixed
        {
            return true;
        }

        public function validAuthenticationResponse($request, $result): mixed
        {
            return true;
        }

        public function broadcast(array $channels, $event, array $payload = []): void
        {
            ($this->onBroadcast)($event);
        }
    });

    config(['broadcasting.connections.audit-test' => ['driver' => 'audit-test'], 'broadcasting.default' => 'audit-test']);
}

function useFailingBroadcaster(): void
{
    useTestBroadcaster(fn () => throw new BroadcastException('Reverb is unreachable'));
}

// ── #1 Reverb outage / broadcast-after-commit ───────────────────────────

test('a failing broadcast no longer stops LeadReceived from queueing auto-assignment', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    useFailingBroadcaster();
    Queue::fake();

    event(new LeadReceived($lead));

    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === AutoAssignLead::class);
});

test('a failing broadcast no longer rolls back a Kanban status move', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);
    useFailingBroadcaster();

    Livewire::actingAs($rep)->test(Index::class)->call('completeInitialLoad')
        ->call('moveLead', $lead->id, leadStatus('contacted')->id);

    expect($lead->fresh()->status_id)->toBe(leadStatus('contacted')->id);
});

test('LeadUpdated fired inside a transaction is only broadcast once that transaction commits', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $broadcasts = 0;
    useTestBroadcaster(function () use (&$broadcasts): void {
        $broadcasts++;
    });

    DB::transaction(function () use ($lead, &$broadcasts): void {
        event(new LeadUpdated($lead));

        expect($broadcasts)->toBe(0);
    });

    expect($broadcasts)->toBe(1);
});

// ── #2 Indexes ──────────────────────────────────────────────────────────

test('lead child tables are indexed by lead_id', function (string $table, string $index) {
    $indexes = collect(DB::getSchemaBuilder()->getIndexes($table))->pluck('name');

    expect($indexes)->toContain($index);
})->with([
    ['lead_status_logs', 'lead_status_logs_lead_id_created_at_index'],
    ['lead_assignments', 'lead_assignments_lead_id_created_at_index'],
    ['lead_notes', 'lead_notes_lead_id_created_at_index'],
    ['lead_detail_update_logs', 'lead_detail_update_logs_lead_id_created_at_index'],
    ['lead_consent_events', 'lead_consent_events_lead_id_created_at_index'],
    ['messages', 'messages_lead_id_created_at_index'],
    ['leads', 'leads_source_id_index'],
    ['leads', 'leads_merged_into_lead_id_index'],
]);

// ── #3 Merge link must involve the current lead ─────────────────────────

/**
 * @return array{tenant: mixed, clientAdmin: mixed, current: Lead, a: Lead, b: Lead, link: LeadDuplicateLink}
 */
function unrelatedDuplicateLink(): array
{
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $current = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $a = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $b = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $link = LeadDuplicateLink::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $a->id, 'duplicate_lead_id' => $b->id]);

    return compact('tenant', 'clientAdmin', 'current', 'a', 'b', 'link');
}

test('the merge modal refuses a duplicate link the current lead is not part of', function () {
    $x = unrelatedDuplicateLink();

    Livewire::actingAs($x['clientAdmin'])->test(Show::class, ['lead' => $x['current']])->call('completeInitialLoad')
        ->call('openMergeModal', $x['link']->id)
        ->assertSet('showMergeModal', false)
        ->assertSet('mergeLinkId', null);
});

test('the merge link id cannot be set from the client', function () {
    $x = unrelatedDuplicateLink();

    Livewire::actingAs($x['clientAdmin'])->test(Show::class, ['lead' => $x['current']])->call('completeInitialLoad')
        ->set('mergeLinkId', $x['link']->id);
})->throws(CannotUpdateLockedPropertyException::class);

test('dismissing a duplicate link the current lead is not part of changes nothing', function () {
    $x = unrelatedDuplicateLink();

    Livewire::actingAs($x['clientAdmin'])->test(Show::class, ['lead' => $x['current']])->call('completeInitialLoad')
        ->call('dismissDuplicate', $x['link']->id);

    expect($x['link']->fresh()->status)->toBe(LeadDuplicateLinkStatus::Pending);
});

test('LeadMergeService refuses a link that does not belong to the two leads being merged', function () {
    $x = unrelatedDuplicateLink();

    expect(fn () => app(LeadMergeService::class)->merge($x['current'], $x['a'], $x['clientAdmin'], [], $x['link']))
        ->toThrow(LeadMergeException::class);
    expect($x['a']->fresh()->merged_into_lead_id)->toBeNull();
});

// ── #4 Delete password cannot be skipped ────────────────────────────────

test('calling Show::delete() directly without the password does not delete the lead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('unqualified')->id]);

    Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->call('delete')
        ->assertHasErrors('deletePassword');

    expect(Lead::whereKey($lead->id)->exists())->toBeTrue();
});

test('calling Index::delete() directly with a wrong password does not delete the lead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')
        ->set('deletePassword', 'not-the-password')
        ->call('delete', $lead->id)
        ->assertHasErrors('deletePassword');

    expect(Lead::whereKey($lead->id)->exists())->toBeTrue();
});

test('the password-confirmed entry point still deletes with the right password', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')
        ->call('confirmDeleteLead', $lead->id)
        ->set('deletePassword', 'password')
        ->call('deleteLeadConfirmed');

    expect(Lead::whereKey($lead->id)->exists())->toBeFalse();
});

// ── #5 Auto-assignment ─────────────────────────────────────────────────

test('auto-assign never overwrites an assignment made before the queued listener ran', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $tenant->update(['lead_distribution_mode' => LeadDistributionMode::Automatic, 'auto_distribution_method' => AutoDistributionMethod::RoundRobin]);
    clientUserWithLeadPermission($tenant, ['view', 'update']);
    $chosenByAdmin = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $chosenByAdmin->id]);

    app(LeadAssignmentService::class)->autoAssign($lead);

    expect($lead->fresh()->assigned_to)->toBe($chosenByAdmin->id);
    expect($lead->assignments()->count())->toBe(0);
});

test('auto-assign skips a lead that has been merged away', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $tenant->update(['lead_distribution_mode' => LeadDistributionMode::Automatic, 'auto_distribution_method' => AutoDistributionMethod::RoundRobin]);
    clientUserWithLeadPermission($tenant, ['view', 'update']);
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'merged_into_lead_id' => $survivor->id]);

    app(LeadAssignmentService::class)->autoAssign($loser);

    expect($loser->fresh()->assigned_to)->toBeNull();
});

test('auto-assign never picks a view-only user who could not work the lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $tenant->update(['lead_distribution_mode' => LeadDistributionMode::Automatic, 'auto_distribution_method' => AutoDistributionMethod::RoundRobin]);
    clientUserWithLeadPermission($tenant, ['view']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    app(LeadAssignmentService::class)->autoAssign($lead);

    expect($lead->fresh()->assigned_to)->toBeNull();
});

// ── #6 / #13 / #16 Kanban move ─────────────────────────────────────────

test('a Kanban move recomputes the lead score', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    LeadScoringRule::factory()->create([
        'tenant_id' => $tenant->id,
        'field' => LeadScoringField::Status->value,
        'operator' => LeadScoringOperator::Equals->value,
        'value' => 'contacted',
        'points' => 20,
        'is_active' => true,
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id, 'lead_score' => null]);

    Livewire::actingAs($rep)->test(Index::class)->call('completeInitialLoad')
        ->call('moveLead', $lead->id, leadStatus('contacted')->id);

    expect($lead->fresh()->lead_score)->toBe(20);
});

test('a merged lead cannot be moved on the Kanban board', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'merged_into_lead_id' => $survivor->id]);
    $statusBefore = $loser->status_id;

    Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')
        ->call('moveLead', $loser->id, leadStatus('contacted')->id);

    expect($loser->fresh()->status_id)->toBe($statusBefore);
});

test('reopening a closed lead clears its closed_at', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('won')->id, 'closed_at' => now()->subWeek()]);

    Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')
        ->call('moveLead', $lead->id, leadStatus('contacted')->id);

    expect($lead->fresh()->closed_at)->toBeNull();
});

// ── #7 Nurturing task pile-up ──────────────────────────────────────────

test('nurturing never stacks a second follow-up task while one is still pending', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $tenant->update(['lead_nurturing_task_enabled' => true, 'lead_nurturing_inactive_days' => 0]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Artisan::call('leads:nurture-inactive-leads');
    $this->travel(1)->days();
    Artisan::call('leads:nurture-inactive-leads');

    expect(Task::where('lead_id', $lead->id)->count())->toBe(1);

    Task::where('lead_id', $lead->id)->update(['status' => TaskStatus::Completed]);
    $this->travel(1)->days();
    Artisan::call('leads:nurture-inactive-leads');

    expect(Task::where('lead_id', $lead->id)->count())->toBe(2);
});

// ── #8 Score sweep ─────────────────────────────────────────────────────

test('the score sweep reads each tenant\'s rules once, not once per lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    LeadScoringRule::factory()->create(['tenant_id' => $tenant->id, 'field' => LeadScoringField::Priority->value, 'operator' => LeadScoringOperator::Equals->value, 'value' => 'urgent', 'points' => 5, 'is_active' => true]);
    Lead::factory()->count(3)->create(['tenant_id' => $tenant->id]);
    $ruleQueries = 0;
    DB::listen(function ($query) use (&$ruleQueries): void {
        if (str_contains($query->sql, '"lead_scoring_rules"')) {
            $ruleQueries++;
        }
    });

    Artisan::call('leads:recalculate-scores');

    expect($ruleQueries)->toBe(1);
});

test('the score sweep does not rewrite an already-unscored lead of a tenant without rules', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $computedAt = now()->subDay()->startOfSecond();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'lead_score' => null, 'lead_score_computed_at' => $computedAt]);

    Artisan::call('leads:recalculate-scores');

    expect($lead->fresh()->lead_score_computed_at->equalTo($computedAt))->toBeTrue();
});

// ── #9 Realtime re-render filter ───────────────────────────────────────

test('a Client User board skips re-querying for a lead event outside their scope, but not inside it', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $someoneElse = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $theirs = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $someoneElse->id]);
    $mine = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);
    $component = Livewire::actingAs($rep)->test(Index::class)->call('completeInitialLoad');

    $boardQueries = function (Closure $action): int {
        $count = 0;
        DB::listen(function ($query) use (&$count): void {
            if (str_contains($query->sql, 'limit 500')) {
                $count++;
            }
        });
        $action();

        return $count;
    };

    expect($boardQueries(fn () => $component->call('onLeadUpdated', ['id' => $theirs->id])))->toBe(0);
    expect($boardQueries(fn () => $component->call('onLeadUpdated', ['id' => $mine->id])))->toBeGreaterThan(0);
});

test('a lead reassigned away from a Client User still refreshes their board', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $other = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);
    $component = Livewire::actingAs($rep)->test(Index::class)->call('completeInitialLoad');

    $lead->update(['assigned_to' => $other->id]);
    $component->call('onLeadUpdated', ['id' => $lead->id]);

    expect($component->get('boardLeadIds'))->not->toContain($lead->id);
});

// ── #10 First response ─────────────────────────────────────────────────

test('a successfully sent outbound message stamps first_contacted_at once', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp, 'config' => ['phone_number_id' => '1', 'access_token' => 'token']]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'first_contacted_at' => null]);
    $conversation = Conversation::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id, 'last_inbound_at' => now()]);
    $send = function () use ($tenant, $lead, $conversation): void {
        $message = Message::factory()->outbound()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'conversation_id' => $conversation->id, 'channel' => MessageChannel::Whatsapp, 'body' => 'Hi']);
        (new SendOutboundMessage($message))->handle(app(OutboundEligibilityService::class), app(MessageSenderFactory::class));
    };
    Http::fake(['graph.facebook.com/*' => Http::sequence()
        ->push(['messages' => [['id' => 'wamid.FIRST']]], 200)
        ->push(['messages' => [['id' => 'wamid.SECOND']]], 200)]);

    $send();
    $firstStamp = $lead->fresh()->first_contacted_at;
    $this->travel(1)->hour();
    $send();

    expect($firstStamp)->not->toBeNull();
    expect($lead->fresh()->first_contacted_at->equalTo($firstStamp))->toBeTrue();
});

test('a failed outbound message does not stamp first_contacted_at', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp, 'config' => ['phone_number_id' => '1', 'access_token' => 'token']]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'first_contacted_at' => null]);
    $conversation = Conversation::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id, 'last_inbound_at' => now()]);
    $message = Message::factory()->outbound()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'conversation_id' => $conversation->id, 'channel' => MessageChannel::Whatsapp, 'body' => 'Hi']);
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'nope']], 400)]);

    (new SendOutboundMessage($message))->handle(app(OutboundEligibilityService::class), app(MessageSenderFactory::class));

    expect($lead->fresh()->first_contacted_at)->toBeNull();
});

// ── #11 Legacy website form validation ─────────────────────────────────

function legacyWebsiteFormSource(): array
{
    ['tenant' => $tenant] = tenantWithLeadManagement();
    LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm, 'config' => ['api_key' => 'legacy-key']]);

    return ['tenant' => $tenant, 'url' => route('api.webhook.website-form', $tenant->tenant_code), 'headers' => ['X-Api-Key' => 'legacy-key']];
}

test('the legacy website form rejects a malformed payload without creating a lead', function (array $payload) {
    ['tenant' => $tenant, 'url' => $url, 'headers' => $headers] = legacyWebsiteFormSource();

    $this->postJson($url, $payload, $headers)->assertStatus(422);

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(0);
})->with([
    'array name' => [['name' => ['Jane', 'Doe']]],
    'invalid email' => [['name' => 'Jane', 'email' => 'not-an-email']],
    'oversized extra data' => [['name' => 'Jane', 'notes' => str_repeat('x', 20000)]],
]);

test('the legacy website form still accepts a well-formed submission', function () {
    ['tenant' => $tenant, 'url' => $url, 'headers' => $headers] = legacyWebsiteFormSource();

    $this->postJson($url, ['name' => 'Jane Doe', 'email' => 'jane@example.com', 'company' => 'Acme'], $headers)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->value('name'))->toBe('Jane Doe');
});

// ── #12 Webhook signature uses the token's own source ──────────────────

function whatsappTextPayload(): array
{
    return ['entry' => [['changes' => [['value' => ['messages' => [['from' => '8801700000000', 'text' => ['body' => 'Hello']]]]]]]]];
}

test('the token webhook route verifies against the token source\'s own app secret', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp, 'created_at' => now()->subDay(), 'config' => ['phone_number_id' => '111', 'access_token' => 'x', 'verify_token' => 'y', 'app_secret' => 'first-secret']]);
    $second = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp, 'webhook_token_hash' => hash('sha256', 'second-token'), 'config' => ['phone_number_id' => '222', 'access_token' => 'x', 'verify_token' => 'y', 'app_secret' => 'second-secret']]);
    $payload = whatsappTextPayload();

    $this->postJson(route('api.webhook.whatsapp.token', [$tenant->tenant_code, 'second-token']), $payload, [
        'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', json_encode($payload), 'second-secret'),
    ])->assertOk();

    expect(Lead::where('source_id', $second->id)->count())->toBe(1);
});

test('repeated forged webhook requests record the rejection at most once a minute', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp, 'config' => ['phone_number_id' => '1', 'access_token' => 'x', 'verify_token' => 'y', 'app_secret' => 'real-secret']]);
    $forge = fn () => $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), whatsappTextPayload(), ['X-Hub-Signature-256' => 'sha256=forged'])->assertForbidden();

    $forge();
    $firstRejection = $source->fresh()->last_signature_rejection_at;
    $this->travel(10)->seconds();
    $forge();

    expect($source->fresh()->last_signature_rejection_at->equalTo($firstRejection))->toBeTrue();
});

// ── #15 Inactive users can't be assigned work ──────────────────────────

test('a lead cannot be assigned to a deactivated user', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $inactive = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $inactive->update(['is_active' => false]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('assigneeId', $inactive->id)
        ->call('assign');

    expect($lead->fresh()->assigned_to)->toBeNull();
});

test('a bulk assignment to a deactivated user is refused', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $inactive = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $inactive->update(['is_active' => false]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')
        ->set('selectedLeadIds', [$lead->id])
        ->call('openBulkAssignModal')
        ->set('bulkAssigneeId', $inactive->id)
        ->call('applyBulkAssign')
        ->assertHasErrors('bulkAssigneeId');

    expect($lead->fresh()->assigned_to)->toBeNull();
});

test('a deactivated user cannot be picked as a new deal owner, but an existing one does not block editing', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $inactive = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $inactive->update(['is_active' => false]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->call('createDeal')
        ->set('dealName', 'New Deal')
        ->set('dealOwnerId', $inactive->id)
        ->call('saveDeal')
        ->assertHasErrors('dealOwnerId');

    $deal = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'name' => 'Old', 'owner_id' => $inactive->id]);

    Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->call('editDeal', $deal->id)
        ->set('dealName', 'Renamed')
        ->call('saveDeal')
        ->assertHasNoErrors();

    expect($deal->fresh()->name)->toBe('Renamed');
});
