<?php

use App\Enums\LeadSourceType;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadAttachment;
use App\Models\LeadSource;
use Livewire\Livewire;

test('a lead with an image attachment and no caption shows a clickable "Sent an image" link to the download route', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookMessenger]);
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'message' => null,
        'source_data' => ['messages' => [[
            'sender' => ['id' => 'psid-1'],
            'timestamp' => now()->subMinutes(5)->getTimestampMs(),
            'message' => ['attachments' => [['type' => 'image', 'payload' => ['url' => 'https://scontent.xx.fbcdn.net/photo.jpg']]]],
        ]]],
    ]);
    $attachment = LeadAttachment::factory()->create([
        'lead_id' => $lead->id,
        'type' => 'image',
        'sent_at' => now()->subMinutes(5),
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->assertSee('Sent an image')
        ->assertSeeHtml(route('tenant-leads.attachment.download', [$lead, $attachment]));
});

test('a lead with a non-image attachment and a caption shows both the link and the caption text', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp]);
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'message' => 'Here is the invoice',
        'source_data' => ['messages' => [[
            'from' => '8801700000000',
            'timestamp' => (string) now()->subMinutes(5)->timestamp,
            'type' => 'document',
            'document' => ['id' => 'media-1', 'caption' => 'Here is the invoice'],
        ]]],
    ]);
    $attachment = LeadAttachment::factory()->create([
        'lead_id' => $lead->id,
        'type' => 'document',
        'sent_at' => now()->subMinutes(5),
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->assertSee('Sent a document')
        ->assertSee('Here is the invoice')
        ->assertSeeHtml(route('tenant-leads.attachment.download', [$lead, $attachment]));
});

test('an email lead with an attachment shows both its body and the attachment link, not one instead of the other', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'message' => 'Please find the invoice attached.',
        'html_body' => null,
        'source_data' => ['subject' => 'Invoice'],
    ]);
    $attachment = LeadAttachment::factory()->create([
        'lead_id' => $lead->id,
        'type' => 'file',
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->assertSee('Please find the invoice attached.')
        ->assertSee('Sent a file')
        ->assertSeeHtml(route('tenant-leads.attachment.download', [$lead, $attachment]));
});

test('a lead with no attachment shows no attachment link', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'message' => 'Just a text message.',
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->assertDontSee('Sent a');
});

test('text messages and attachments are shown interleaved in the order they actually arrived', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookMessenger]);

    $t0 = now()->subMinutes(10);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'message' => "Hi there\nHere's a photo\nAnd another one",
        'source_data' => ['messages' => [
            ['sender' => ['id' => 'psid-1'], 'timestamp' => $t0->clone()->getTimestampMs(), 'message' => ['text' => 'Hi there']],
            ['sender' => ['id' => 'psid-1'], 'timestamp' => $t0->clone()->addMinutes(2)->getTimestampMs(), 'message' => ['attachments' => [['type' => 'image', 'payload' => ['url' => 'https://scontent.xx.fbcdn.net/a.jpg']]]]],
            ['sender' => ['id' => 'psid-1'], 'timestamp' => $t0->clone()->addMinutes(4)->getTimestampMs(), 'message' => ['text' => "Here's a photo"]],
            ['sender' => ['id' => 'psid-1'], 'timestamp' => $t0->clone()->addMinutes(6)->getTimestampMs(), 'message' => ['attachments' => [['type' => 'video', 'payload' => ['url' => 'https://scontent.xx.fbcdn.net/b.mp4']]]]],
            ['sender' => ['id' => 'psid-1'], 'timestamp' => $t0->clone()->addMinutes(8)->getTimestampMs(), 'message' => ['text' => 'And another one']],
        ]],
    ]);

    LeadAttachment::factory()->create(['lead_id' => $lead->id, 'type' => 'image', 'sent_at' => $t0->clone()->addMinutes(2)]);
    LeadAttachment::factory()->create(['lead_id' => $lead->id, 'type' => 'video', 'sent_at' => $t0->clone()->addMinutes(6)]);

    $timeline = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->instance()
        ->timeline();

    $shape = collect($timeline)->map(fn (array $item): string => $item['type'] === 'text' ? "text:{$item['text']}" : "attachment:{$item['attachment']->type}")->all();

    expect($shape)->toBe([
        'text:Hi there',
        'attachment:image',
        "text:Here's a photo",
        'attachment:video',
        'text:And another one',
    ]);
});
