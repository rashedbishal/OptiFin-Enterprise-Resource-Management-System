<?php

use App\Actions\Tenant\DeleteLeadConversation;
use App\Enums\LeadSourceType;
use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Jobs\DownloadLeadAttachment;
use App\Jobs\SendOutboundMessage;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadAttachment;
use App\Models\LeadConversationDeletion;
use App\Models\LeadNote;
use App\Models\LeadSource;
use App\Models\Message;
use App\Services\MessageSenderFactory;
use App\Services\OutboundEligibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake('local'));

/**
 * A Messenger lead with a full conversation: two inbound texts, an outbound
 * text, an outbound PDF (plus a failed twin sharing the same file), an
 * inbound image attachment, the legacy source_data copy and leads.message —
 * everything the deletion has to reach — with real files on disk.
 *
 * @return array{tenant: mixed, admin: mixed, lead: Lead, source: LeadSource, conversation: Conversation, files: array<int, string>}
 */
function conversationLead(LeadSourceType $type = LeadSourceType::FacebookMessenger): array
{
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => $type, 'config' => ['page_access_token' => 't', 'page_id' => '123', 'verify_token' => 'v']]);
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'message' => "Hello\nAre you there?",
        'source_data' => ['facebook_user_id' => 'psid-1', 'messages' => [['message' => ['mid' => 'm1', 'text' => 'Hello']], ['message' => ['mid' => 'm2', 'text' => 'Are you there?']]]],
    ]);
    $conversation = Conversation::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id,
        'channel' => MessageChannel::fromLeadSourceType($type), 'last_inbound_at' => now(),
    ]);
    $base = ['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'conversation_id' => $conversation->id, 'channel' => $conversation->channel];

    Message::factory()->create($base + ['body' => 'Hello', 'external_message_id' => 'm1']);
    Message::factory()->create($base + ['body' => 'Are you there?', 'external_message_id' => 'm2']);
    Message::factory()->outbound()->withStatus(MessageStatus::Sent)->create($base + ['body' => 'Yes!']);

    $outbound = "outbound/{$tenant->tenant_code}/messenger/quote.pdf";
    $pdf = ['message_type' => MessageType::Document, 'body' => null, 'attachment_path' => $outbound, 'attachment_mime' => 'application/pdf', 'attachment_name' => 'quote.pdf', 'attachment_size' => 9];
    Message::factory()->outbound()->withStatus(MessageStatus::Failed)->create($base + $pdf);
    Message::factory()->outbound()->withStatus(MessageStatus::Sent)->create($base + $pdf);

    $inbound = "leads/{$tenant->tenant_code}/messenger/psid-1_01.jpg";
    LeadAttachment::factory()->create(['lead_id' => $lead->id, 'path' => $inbound, 'type' => 'image', 'mime' => 'image/jpeg']);

    Storage::disk('local')->put($outbound, '%PDF-1.4 x');
    Storage::disk('local')->put($inbound, 'jpeg-bytes');

    return compact('tenant', 'admin', 'lead', 'source', 'conversation') + ['files' => [$outbound, $inbound]];
}

function deleteConversationAs(mixed $user, Lead $lead, string $password = 'password')
{
    return Livewire::actingAs($user)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->call('confirmDeleteConversation')
        ->set('deletePassword', $password)
        ->call('deleteConversationConfirmed');
}

test('deleting a conversation removes its messages, files and raw copy but keeps the lead and its identity data', function () {
    ['tenant' => $tenant, 'admin' => $admin, 'lead' => $lead, 'files' => $files] = conversationLead();
    LeadNote::factory()->create(['lead_id' => $lead->id, 'tenant_user_id' => $admin->id]);
    $other = conversationLead();

    deleteConversationAs($admin, $lead)->assertHasNoErrors()->assertSet('showDeleteConversationModal', false)->assertDispatched('toast-show');

    expect(Message::where('lead_id', $lead->id)->count())->toBe(0)
        ->and(Conversation::where('lead_id', $lead->id)->count())->toBe(0)
        ->and(LeadAttachment::where('lead_id', $lead->id)->count())->toBe(0);
    foreach ($files as $path) {
        Storage::disk('local')->assertMissing($path);
    }

    $lead->refresh();
    expect(Lead::find($lead->id))->not->toBeNull()
        ->and($lead->message)->toBeNull()
        ->and($lead->source_data)->toBe(['facebook_user_id' => 'psid-1'])
        ->and($lead->notes ?? $lead->leadNotes)->toHaveCount(1);

    // Another lead's conversation, rows and files, is untouched.
    expect(Message::where('lead_id', $other['lead']->id)->count())->toBe(5);
    foreach ($other['files'] as $path) {
        Storage::disk('local')->assertExists($path);
    }
});

test('the deletion is audited with counts only, never message content', function () {
    ['admin' => $admin, 'lead' => $lead, 'tenant' => $tenant] = conversationLead();

    deleteConversationAs($admin, $lead);

    $audit = LeadConversationDeletion::sole();
    expect($audit->tenant_id)->toBe($tenant->id)
        ->and($audit->lead_id)->toBe($lead->id)
        ->and($audit->lead_identifier)->toBe($lead->identifier)
        ->and($audit->channel)->toBe('facebook_messenger')
        ->and($audit->deleted_by)->toBe($admin->id)
        ->and($audit->summary)->toBe(['messages' => 5, 'conversations' => 1, 'attachments' => 1, 'files' => 2])
        ->and(json_encode($audit->getAttributes()))->not->toContain('Are you there');
});

test('a wrong or missing password deletes nothing', function () {
    ['admin' => $admin, 'lead' => $lead, 'files' => $files] = conversationLead();

    deleteConversationAs($admin, $lead, 'not-my-password')->assertHasErrors(['deletePassword']);
    deleteConversationAs($admin, $lead, '')->assertHasErrors(['deletePassword' => 'required']);

    expect(Message::where('lead_id', $lead->id)->count())->toBe(5)->and(LeadConversationDeletion::count())->toBe(0);
    foreach ($files as $path) {
        Storage::disk('local')->assertExists($path);
    }
});

test('a client user without delete permission cannot open or run the deletion', function () {
    ['tenant' => $tenant, 'lead' => $lead, 'files' => $files] = conversationLead();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead->update(['assigned_to' => $rep->id]);

    $component = Livewire::actingAs($rep)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad');
    $component->call('confirmDeleteConversation')->assertSet('showDeleteConversationModal', false);
    $component->set('deletePassword', 'password')->call('deleteConversation');
    $component->assertDontSee('Delete conversation');

    expect(Message::where('lead_id', $lead->id)->count())->toBe(5)->and(LeadConversationDeletion::count())->toBe(0);
    Storage::disk('local')->assertExists($files[0]);
});

test('a lead whose source has no conversation is refused and left untouched', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'message' => 'Form message', 'source_data' => ['messages' => [['x' => 1]]]]);

    Livewire::actingAs($admin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')->set('deletePassword', 'password')->call('deleteConversation')->assertDispatched('toast-show');

    expect($lead->fresh()->message)->toBe('Form message')->and($lead->fresh()->source_data)->toHaveKey('messages')->and(LeadConversationDeletion::count())->toBe(0);
});

test('a lead merged away while its page is open cannot have its conversation deleted', function () {
    ['admin' => $admin, 'lead' => $lead, 'tenant' => $tenant] = conversationLead();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $component = Livewire::actingAs($admin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad');
    DB::table('leads')->where('id', $lead->id)->update(['merged_into_lead_id' => $survivor->id]);

    $component->set('deletePassword', 'password')->call('deleteConversation')->assertDispatched('toast-show');

    expect(Message::where('lead_id', $lead->id)->count())->toBe(5)->and(LeadConversationDeletion::count())->toBe(0);
});

// ------------------------------------------------------------------- UI

test('the button and confirmation show the counts, and disappear once there is nothing to delete', function () {
    ['admin' => $admin, 'lead' => $lead] = conversationLead();

    $component = Livewire::actingAs($admin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->assertSee('Delete conversation')
        ->assertDontSee('permanently deletes 5 messages and 2 files');

    $component->call('confirmDeleteConversation')
        ->assertSee('permanently deletes 5 messages and 2 files');

    $component->set('deletePassword', 'password')->call('confirmDeleteConversation')->set('deletePassword', 'password')->call('deleteConversationConfirmed')
        ->assertDontSee('Delete conversation');
});

test('after deletion the feed is empty, the composer has no history, and a new inbound message reuses the same lead', function () {
    ['tenant' => $tenant, 'admin' => $admin, 'lead' => $lead] = conversationLead();

    $component = deleteConversationAs($admin, $lead);
    expect($lead->fresh()->messageTimeline())->toBe([]);
    $component->assertSee('No conversation history yet for this channel.');

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), [
        'entry' => [['messaging' => [['sender' => ['id' => 'psid-1'], 'message' => ['mid' => 'm_new', 'text' => 'Back again']]]]],
    ])->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(array_column($lead->fresh()->messageTimeline(), 'text'))->toBe(['Back again'])
        ->and(Message::where('lead_id', $lead->id)->count())->toBe(1);
});

// ---------------------------------------------------- queued jobs, action

test('a queued send or download for a deleted conversation is skipped instead of failing', function () {
    ['lead' => $lead, 'tenant' => $tenant, 'conversation' => $conversation] = conversationLead();
    $message = Message::factory()->outbound()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'conversation_id' => $conversation->id, 'channel' => MessageChannel::Messenger, 'body' => 'queued',
    ]);
    $attachment = LeadAttachment::where('lead_id', $lead->id)->first();
    Http::fake();

    app(DeleteLeadConversation::class)->handle($lead, null);

    (new SendOutboundMessage($message))->handle(app(OutboundEligibilityService::class), app(MessageSenderFactory::class));
    (new SendOutboundMessage($message))->failed(null);
    (new DownloadLeadAttachment($attachment, 'psid-1', 'facebook_messenger', 'https://example.com/x.jpg'))->handle();

    Http::assertNothingSent();
});
