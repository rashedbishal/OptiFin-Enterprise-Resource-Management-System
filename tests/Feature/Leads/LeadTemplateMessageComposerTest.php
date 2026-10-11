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
use App\Models\WhatsAppMessageTemplate;
use App\Services\LeadMergeService;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * WhatsApp Message Templates — the composer's recovery path once a lead's
 * 24-hour messaging window has closed. Mirrors LeadMessageComposerTest's
 * setup shape exactly, but against an expired window.
 */

test('a client admin can send a registered template once the window has closed', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '1', 'access_token' => 'token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    Conversation::factory()->windowExpired()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id,
    ]);
    $template = WhatsAppMessageTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_source_id' => $source->id,
        'name' => 'order_update',
        'language' => 'en_US',
        'variable_count' => 1,
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEMPLATE']]], 200)]);

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
    expect($message->template_language)->toBe('en_US');
    expect($message->template_variables)->toBe(['ORD-12345']);
    expect($message->status)->toBe(MessageStatus::Sent);
    expect($message->sent_by)->toBe($clientAdmin->id);
});

/**
 * Picking a template and sizing its variable fields is now entirely
 * Alpine-driven client-side (see message-composer-card.blade.php's own
 * x-data) — switching the picker away from wire:model.live means there is
 * no longer a server-side "template just changed" hook to resize
 * $templateVariableValues automatically; that convenience moved to the
 * browser (untestable from Pest, which has no JS engine). What stays a
 * real server-side concern is safety: a submission whose variable count
 * doesn't match the picked template's must still be rejected, whether
 * that mismatch came from a stale value, a slow/failed Alpine watcher, or
 * a tampered request — sendTemplateMessage()'s own validate() is the
 * thing actually protecting that now, proven directly below.
 */
test('submitting a variable-count mismatch is rejected by validation, not silently sent', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '1', 'access_token' => 'token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    Conversation::factory()->windowExpired()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id,
    ]);
    $twoVariableTemplate = WhatsAppMessageTemplate::factory()->create([
        'tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'variable_count' => 2,
    ]);

    Http::fake();

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->set('selectedTemplateId', $twoVariableTemplate->id)
        // Stale single-value array, as if picked from a previous
        // (0- or 1-variable) template and never resized — exactly what
        // Alpine's client-side watcher exists to prevent in the browser.
        ->set('templateVariableValues', ['only-one-value'])
        ->call('sendTemplateMessage')
        ->assertHasErrors(['templateVariableValues']);

    Http::assertNothingSent();
    expect(Message::where('lead_id', $lead->id)->exists())->toBeFalse();
});

test('a merged lead cannot be sent a template either', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '1', 'access_token' => 'token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    Conversation::factory()->windowExpired()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id,
    ]);
    $template = WhatsAppMessageTemplate::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'variable_count' => 0]);
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad');

    // Merged away by someone else, in a separate request, after this
    // component was already mounted — same "leadIsMutable()" scenario as
    // LeadMessageComposerTest's own sendMessage() mid-session-merge test;
    // mounting Show directly on an already-merged lead instead redirects
    // in mount() itself, which isn't the scenario this guards against.
    app(LeadMergeService::class)->merge($survivor, $lead->fresh(), $clientAdmin, []);

    Http::fake();

    $component
        ->set('selectedTemplateId', $template->id)
        ->call('sendTemplateMessage')
        ->assertDispatched('toast-show');

    Http::assertNothingSent();
    expect(Message::where('lead_id', $lead->id)->exists())->toBeFalse();
});

test('an inactive template is never offered by whatsappTemplates', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '1', 'access_token' => 'token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    Conversation::factory()->windowExpired()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id,
    ]);
    WhatsAppMessageTemplate::factory()->inactive()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id]);

    $templates = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->instance()
        ->whatsappTemplates();

    expect($templates)->toBeEmpty();
});
