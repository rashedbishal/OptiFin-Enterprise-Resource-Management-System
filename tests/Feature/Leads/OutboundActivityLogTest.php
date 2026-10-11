<?php

use App\Enums\LeadSourceType;
use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Message;
use App\Services\LeadMergeService;
use Livewire\Livewire;

/*
 * The Activity Log card's "hide this entry" (X button) — Client Admin
 * only, confirmed via a plain Yes/No modal. Deliberately NOT a delete:
 * the message row (and its bubble in the merged Messenger-style chat
 * card's conversation thread) is untouched — only this one list stops
 * showing it, via Message.activity_log_hidden_at.
 */

function outboundActivityLogScenario(): array
{
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '1', 'access_token' => 'token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id, 'last_inbound_at' => now(),
    ]);
    $message = Message::factory()->outbound()->withStatus(MessageStatus::Sent)->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'lead_id' => $lead->id,
        'channel' => MessageChannel::Whatsapp,
        'body' => 'Hello from the activity log test',
    ]);

    return compact('tenant', 'clientAdmin', 'source', 'lead', 'conversation', 'message');
}

test('a client admin can hide an outbound message from the activity log', function () {
    $x = outboundActivityLogScenario();

    Livewire::actingAs($x['clientAdmin'])
        ->test(Show::class, ['lead' => $x['lead']])
        ->call('completeInitialLoad')
        ->call('hideOutboundMessage', $x['message']->id);

    expect($x['message']->fresh()->activity_log_hidden_at)->not->toBeNull();
});

test('a hidden message disappears from outboundMessages but the row itself, and its place in the conversation timeline, are untouched', function () {
    $x = outboundActivityLogScenario();

    $component = Livewire::actingAs($x['clientAdmin'])
        ->test(Show::class, ['lead' => $x['lead']])
        ->call('completeInitialLoad');

    expect($component->get('outboundMessages')->pluck('id'))->toContain($x['message']->id);

    $component->call('hideOutboundMessage', $x['message']->id);

    expect($component->get('outboundMessages')->pluck('id'))->not->toContain($x['message']->id);
    expect(Message::find($x['message']->id))->not->toBeNull();

    // Show::timeline() items carry no stable "id" for a message-type entry
    // (only an attachment-type one does, via its own nested
    // LeadAttachment) — so the conversation thread being untouched is
    // proven by the message's own text still appearing there, the same
    // way the chat bubble itself renders it.
    unset($component->instance()->timeline);
    $timelineTexts = collect($component->instance()->timeline())->pluck('text');
    expect($timelineTexts)->toContain('Hello from the activity log test');
});

test('the confirm-modal flow hides the message only after confirmation', function () {
    $x = outboundActivityLogScenario();

    $component = Livewire::actingAs($x['clientAdmin'])
        ->test(Show::class, ['lead' => $x['lead']])
        ->call('completeInitialLoad')
        ->call('confirmHideOutboundMessage', $x['message']->id)
        ->assertSet('showHideOutboundMessageModal', true)
        ->assertSet('confirmingHideOutboundMessageId', $x['message']->id);

    expect($x['message']->fresh()->activity_log_hidden_at)->toBeNull();

    $component->call('hideOutboundMessageConfirmed')
        ->assertSet('showHideOutboundMessageModal', false)
        ->assertSet('confirmingHideOutboundMessageId', null);

    expect($x['message']->fresh()->activity_log_hidden_at)->not->toBeNull();
});

test('a client user (non-admin) cannot see the X button and the action refuses them server-side', function () {
    $x = outboundActivityLogScenario();
    $rep = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $x['lead']->update(['assigned_to' => $rep->id]);

    $component = Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $x['lead']])
        ->call('completeInitialLoad');

    $component->assertDontSeeHtml('wire:click="confirmHideOutboundMessage(\''.$x['message']->id.'\')"');

    $component->call('hideOutboundMessage', $x['message']->id);

    expect($x['message']->fresh()->activity_log_hidden_at)->toBeNull();
});

test('a client admin sees the X button', function () {
    $x = outboundActivityLogScenario();

    Livewire::actingAs($x['clientAdmin'])
        ->test(Show::class, ['lead' => $x['lead']])
        ->call('completeInitialLoad')
        ->assertSeeHtml('wire:click="confirmHideOutboundMessage(\''.$x['message']->id.'\')"');
});

test('hiding a message on a merged lead is refused', function () {
    $x = outboundActivityLogScenario();
    $survivor = Lead::factory()->create(['tenant_id' => $x['tenant']->id, 'source_id' => $x['source']->id]);

    $component = Livewire::actingAs($x['clientAdmin'])
        ->test(Show::class, ['lead' => $x['lead']])
        ->call('completeInitialLoad');

    app(LeadMergeService::class)->merge($survivor, $x['lead']->fresh(), $x['clientAdmin'], []);

    $component->call('hideOutboundMessage', $x['message']->id);

    expect($x['message']->fresh()->activity_log_hidden_at)->toBeNull();
});

test('a message from another lead cannot be hidden through this lead\'s Show component', function () {
    $x = outboundActivityLogScenario();
    $otherLead = Lead::factory()->create(['tenant_id' => $x['tenant']->id, 'source_id' => $x['source']->id]);
    $otherConversation = Conversation::factory()->create([
        'tenant_id' => $x['tenant']->id, 'lead_id' => $otherLead->id, 'lead_source_id' => $x['source']->id, 'last_inbound_at' => now(),
    ]);
    $otherMessage = Message::factory()->outbound()->withStatus(MessageStatus::Sent)->create([
        'tenant_id' => $x['tenant']->id,
        'conversation_id' => $otherConversation->id,
        'lead_id' => $otherLead->id,
        'channel' => MessageChannel::Whatsapp,
    ]);

    Livewire::actingAs($x['clientAdmin'])
        ->test(Show::class, ['lead' => $x['lead']])
        ->call('completeInitialLoad')
        ->call('hideOutboundMessage', $otherMessage->id);

    expect($otherMessage->fresh()->activity_log_hidden_at)->toBeNull();
});

test('the activity log card does not render at all once its only message is hidden', function () {
    $x = outboundActivityLogScenario();

    // forceFill(), not update(): activity_log_hidden_at is deliberately
    // not Fillable (see Message's own docblock) — a plain update() would
    // silently no-op here, same trap hideOutboundMessage() itself had to
    // avoid.
    $x['message']->forceFill(['activity_log_hidden_at' => now()])->save();

    Livewire::actingAs($x['clientAdmin'])
        ->test(Show::class, ['lead' => $x['lead']])
        ->call('completeInitialLoad')
        ->assertDontSee('Activity Log');
});

test('Resend on a failed message in the activity log is still only offered to a user who can update the lead', function () {
    $x = outboundActivityLogScenario();
    $x['message']->update(['status' => MessageStatus::Failed, 'failure_reason' => 'Recipient not on WhatsApp']);
    $viewOnlyRep = clientUserWithLeadPermission($x['tenant'], ['view']);
    $x['lead']->update(['assigned_to' => $viewOnlyRep->id]);

    Livewire::actingAs($viewOnlyRep)
        ->test(Show::class, ['lead' => $x['lead']])
        ->call('completeInitialLoad')
        ->assertDontSeeHtml('wire:click="resendMessage(\''.$x['message']->id.'\')"');
});
