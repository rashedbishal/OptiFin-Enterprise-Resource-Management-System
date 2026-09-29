<?php

use App\Enums\LeadSourceType;
use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadAttachment;
use App\Models\LeadSource;
use App\Models\Message;
use App\Models\TenantUser;
use Livewire\Livewire;

/**
 * The Details card renders a messaging lead's conversation as a chat feed:
 * what the customer sent (and every attachment) on the left in a neutral
 * bubble, what this business sent on the right in a blue bubble with its
 * delivery status. Presentation only — these tests pin that the two sides
 * are distinguishable in the rendered page, in the same chronological order
 * as before.
 */
function conversationFeedHtml(): array
{
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '1', 'access_token' => 'token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id, 'last_inbound_at' => now()]);
    $base = ['tenant_id' => $tenant->id, 'conversation_id' => $conversation->id, 'lead_id' => $lead->id, 'channel' => MessageChannel::Whatsapp];

    Message::factory()->create($base + ['body' => 'Hello, is this still available?', 'created_at' => now()->subMinutes(10)]);
    $sender = TenantUser::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Saadman Sakib']);
    Message::factory()->outbound()->withStatus(MessageStatus::Delivered)->create($base + ['sent_by' => $sender->id, 'body' => 'Yes, it is!', 'created_at' => now()->subMinutes(8)]);
    Message::factory()->outbound()->withStatus(MessageStatus::Sent)->create($base + ['sent_by' => $sender->id, 'body' => 'Sent but failed', 'created_at' => now()->subMinutes(6)]);
    LeadAttachment::factory()->create(['lead_id' => $lead->id, 'type' => 'image', 'sent_at' => now()->subMinutes(4)]);
    Message::factory()->create($base + ['body' => 'Thanks a lot', 'created_at' => now()->subMinutes(2)]);

    $html = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')->html();

    return [$html, $lead];
}

/**
 * The feed's rows in rendered order: outer alignment, message-column classes,
 * bubble classes, bubble text and the caption under it. Scoped to the feed
 * rows so the composer card's own copy of outbound messages is not picked up.
 *
 * @return list<array{row: string, column: string, bubble: string, raw: string, text: string, caption: string}>
 */
function feedRows(string $html): array
{
    preg_match_all(
        '/<div class="(flex w-full items-end[^\"]*)">.*?<div class="(flex max-w-\[82%\][^\"]*)">(?:\s|<!--.*?-->)*<span[^>]*>.*?<\/span>(?:\s|<!--.*?-->)*<div class="([^\"]*rounded-2xl[^\"]*)"[^>]*>(.*?)<\/div>(?:\s|<!--.*?-->)*<span[^>]*>(.*?)<\/span>/s',
        $html,
        $matches,
        PREG_SET_ORDER,
    );

    return array_map(fn (array $m): array => [
        'row' => $m[1],
        'column' => $m[2],
        'bubble' => $m[3],
        'raw' => $m[4],
        'text' => trim(preg_replace('/\s+/', ' ', strip_tags($m[4]))),
        'caption' => trim(preg_replace('/\s+/', ' ', strip_tags($m[5] ?? ''))),
    ], $matches);
}

function feedRow(string $html, string $needle): array
{
    foreach (feedRows($html) as $row) {
        if (str_contains($row['text'], $needle)) {
            return $row;
        }
    }

    throw new RuntimeException("No feed row containing [{$needle}] was rendered.");
}

test('sent and received messages render as visibly different chat bubbles', function () {
    [$html] = conversationFeedHtml();

    expect($html)->toContain('contain-paint');
    expect($html)->toContain('radial-gradient(105%_82%_at_100%_0%');

    $incoming = feedRow($html, 'Hello, is this still available?');
    $outgoing = feedRow($html, 'Yes, it is!');

    expect($outgoing['bubble'])->toContain('from-blue-600')->toContain('text-white')->toContain('rounded-br-md')->not->toContain('bg-zinc-100');
    expect($incoming['bubble'])->toContain('bg-white')->toContain('rounded-bl-md')->not->toContain('from-blue-600');

    expect(feedRow($html, 'Thanks a lot')['bubble'])->toContain('bg-white');
    expect(feedRow($html, 'Sent but failed')['bubble'])->toContain('from-blue-600');

    // Assistive technology gets the sender, since colour alone would not say it.
    expect($incoming['text'])->toStartWith('Customer:');
    expect($outgoing['text'])->toStartWith('You:');
});

test('outgoing messages sit on the right and incoming ones on the left, with the outgoing delivery status shown', function () {
    [$html] = conversationFeedHtml();

    expect(feedRow($html, 'Yes, it is!')['row'])->toContain('justify-end');
    expect(feedRow($html, 'Yes, it is!')['column'])->toContain('items-end');
    expect(feedRow($html, 'Yes, it is!')['caption'])->toContain('Delivered');

    $incoming = feedRow($html, 'Hello, is this still available?');
    expect($incoming['row'])->toContain('justify-start');
    expect($incoming['column'])->toContain('items-start');
    expect($incoming['caption'])->not->toContain('Delivered')->not->toContain('Sent');
    expect($incoming['caption'])->not->toContain('by');
});

test('the conversation feed keeps its chronological order and renders the attachment on the incoming side', function () {
    [$html] = conversationFeedHtml();

    $rows = feedRows($html);

    expect(array_map(fn (array $row): string => str_contains($row['row'], 'justify-end') ? 'out' : 'in', $rows))
        ->toBe(['in', 'out', 'out', 'in', 'in']);
    expect($rows[1]['text'])->toContain('Yes, it is!');
    expect($rows[2]['text'])->toContain('Sent but failed');
    expect($rows[4]['text'])->toContain('Thanks a lot');
});

test('an outgoing message is captioned "Sent by" the real sender instead of a bare "Sent"', function () {
    [$html] = conversationFeedHtml();

    // Status Sent collapses into the sender caption; Delivered stays visible beside it.
    expect(feedRow($html, 'Sent but failed')['caption'])->toContain('Sent by Saadman Sakib');
    expect(substr_count(feedRow($html, 'Sent but failed')['caption'], 'Sent'))->toBe(1);
    expect(feedRow($html, 'Yes, it is!')['caption'])->toContain('Sent by Saadman Sakib')->toContain('Delivered');
});

test('a chat bubble carries no stray whitespace around its text, so it has no extra inner padding', function () {
    [$html] = conversationFeedHtml();

    foreach (['Hello, is this still available?', 'Yes, it is!'] as $text) {
        expect(feedRow($html, $text)['raw'])->toMatch('/^<span class="sr-only">[^<]+<\/span>'.preg_quote($text, '/').'$/');
    }
});
