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

/**
 * The composer's picker/variable-fields markup embeds each template's
 * body_text via @js() inside a double-quoted x-data="..." attribute — this
 * exact file/area has already broken rendering twice before from a
 * literal quote or an adjacent "{ {" pair in raw Blade source (see
 * Platform\Clients\LeadSources' Create Template modal history). Render
 * for real with a body_text containing an apostrophe (the one character
 * most likely to appear in genuine template wording and most likely to
 * break a naively-escaped attribute) and assert the attribute boundary
 * itself is intact, not just that some substring is present somewhere on
 * the page.
 */
test('a template body containing an apostrophe renders without breaking the composer markup', function () {
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
    MessengerMessageTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_source_id' => $source->id,
        'variable_count' => 2,
        'body_text' => "Hi {{1}}, we'd love to know if you'd still like help with {{2}}.",
    ]);

    $html = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->html();

    // The send button and the dropdown-select component both still render
    // as real markup after the x-data attribute — if the apostrophe had
    // broken out of the attribute early, the rest of the tag's own
    // attributes (and everything Blade compiled after it) would instead
    // appear as visible, garbled page text, exactly like the earlier bug.
    expect($html)->toContain('wire:submit="sendTemplateMessage"');
    expect($html)->toContain('Send Template');

    // @js() hex-escapes every apostrophe (JSON_HEX_APOS), so the raw text
    // with a literal apostrophe must never appear verbatim — only its
    // escaped hex form should. If a literal apostrophe ever does appear
    // here, either @js() stopped escaping it or this got rewritten to
    // build the JSON some other, unsafe way.
    // @js() here wraps an array (the templates payload), not a bare
    // string, so it goes through Js::from()'s JSON.parse('...') path —
    // the whole JSON text is itself re-encoded as a JS string literal, so
    // the first pass's ' has its own backslash escaped too, landing
    // as a literal double backslash (\\u0027) in the final HTML. Still
    // fully safe (no unescaped quote character anywhere), just doubled.
    $apostrophe = chr(39);
    $hexEscapedApostrophe = chr(92).chr(92).'u0027';
    $rawApostropheText = 'we'.$apostrophe.'d love to know if you'.$apostrophe.'d still like help with';
    $escapedApostropheText = 'we'.$hexEscapedApostrophe.'d love to know if you'.$hexEscapedApostrophe.'d still like help with';

    expect($html)->not->toContain($rawApostropheText);
    expect($html)->toContain($escapedApostropheText);
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
