<?php

use App\Enums\LeadSourceType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Message;
use App\Models\MessengerMessageTemplate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * Messenger Utility Message Templates — the composer's recovery path once
 * a lead's 24-hour messaging window has closed. Mirrors
 * LeadTemplateMessageComposerTest's WhatsApp setup shape exactly, against
 * a Messenger conversation instead.
 */

test('a client admin can send a registered messenger template once the window has closed', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '1', 'page_access_token' => 'page-token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    Conversation::factory()->messenger()->windowExpired()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id,
    ]);
    $template = MessengerMessageTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_source_id' => $source->id,
        'name' => 'order_update',
        'language' => 'en',
        'variable_count' => 1,
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'mid.TEMPLATE'], 200)]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->set('selectedTemplateId', $template->id)
        ->set('templateVariableValues.0', 'ORD-12345')
        ->call('sendTemplateMessage')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    $message = Message::where('lead_id', $lead->id)->where('direction', MessageDirection::Outbound)->first();
    expect($message)->not->toBeNull();
    expect($message->message_type)->toBe(MessageType::Template);
    expect($message->template_name)->toBe('order_update');
    expect($message->template_language)->toBe('en');
    expect($message->template_variables)->toBe(['ORD-12345']);
    expect($message->status)->toBe(MessageStatus::Sent);
    expect($message->sent_by)->toBe($clientAdmin->id);
});

test('an inactive messenger template is never offered by messengerTemplates', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '1', 'page_access_token' => 'page-token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    Conversation::factory()->messenger()->windowExpired()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id,
    ]);
    MessengerMessageTemplate::factory()->inactive()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id]);

    $templates = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->instance()
        ->messengerTemplates();

    expect($templates)->toBeEmpty();
});

test('a whatsapp template is never offered on a messenger conversation and vice versa', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '1', 'page_access_token' => 'page-token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    Conversation::factory()->messenger()->windowExpired()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id,
    ]);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad');

    expect($component->instance()->whatsappTemplates())->toBeEmpty();
    expect($component->instance()->messengerTemplates())->toBeEmpty();
});
