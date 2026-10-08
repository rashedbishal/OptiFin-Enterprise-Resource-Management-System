<?php

use App\Enums\LeadSourceType;
use App\Enums\MessageChannel;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Message;
use Livewire\Livewire;

/*
 * WhatsApp-style "N new message(s)" pill on the conversation feed
 * (detail-card.blade.php). The actual scroll-tracking behavior is
 * client-side Alpine.js (isNearBottom/newCount/scrollToBottom), which Pest
 * can't exercise without a real browser — these pin that the pill's
 * markup and wiring render for a messaging lead, same "assert the markup
 * is present" convention as LeadConversationFeedStyleTest's bubble checks.
 */

test('the new-message pill markup renders for a lead with conversation history', function () {
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
    Message::factory()->create([
        'tenant_id' => $tenant->id, 'conversation_id' => $conversation->id, 'lead_id' => $lead->id,
        'channel' => MessageChannel::Whatsapp, 'body' => 'Hello there',
    ]);

    $html = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')->html();

    expect($html)->toContain('newCount')
        ->toContain('isNearBottom')
        ->toContain('scrollToBottom($refs.feed)')
        ->toContain('x-ref="feed"');
});

test('no feed/pill markup renders for a lead with no conversation history', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    $html = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')->html();

    expect($html)->not->toContain('scrollToBottom($refs.feed)');
});
