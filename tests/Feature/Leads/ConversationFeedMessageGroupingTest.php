<?php

use App\Enums\LeadSourceType;
use App\Enums\MessageChannel;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Message;
use App\Models\TenantUser;
use Livewire\Livewire;

/*
 * WhatsApp-style message grouping on the conversation feed
 * (detail-card.blade.php): consecutive messages from the same side, by the
 * same sender, sent within 60 seconds of each other, collapse their
 * repeated "YOUR TEAM"/sender label and "Sent by :name" caption into a
 * single header instead of restating it on every bubble.
 */

function renderFeedHtmlFor(array $timestamps): string
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
    $sender = TenantUser::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Saadman Sakib']);

    foreach ($timestamps as $i => $createdAt) {
        Message::factory()->outbound()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'lead_id' => $lead->id,
            'channel' => MessageChannel::Whatsapp,
            'sent_by' => $sender->id,
            'body' => 'Message '.$i,
            'created_at' => $createdAt,
        ]);
    }

    return Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')->html();
}

test('messages from the same sender within 60 seconds are grouped under one label', function () {
    $html = renderFeedHtmlFor([now()->subMinutes(10), now()->subMinutes(10)->addSeconds(30)]);

    expect(substr_count($html, 'Your team'))->toBe(1);
    expect($html)->toContain('-mt-3');
});

test('messages from the same sender more than 60 seconds apart each keep their own label', function () {
    $html = renderFeedHtmlFor([now()->subMinutes(10), now()->subMinutes(8)]);

    expect(substr_count($html, 'Your team'))->toBe(2);
});
