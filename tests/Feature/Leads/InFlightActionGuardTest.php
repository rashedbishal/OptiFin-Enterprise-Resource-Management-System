<?php

use App\Enums\LeadSourceType;
use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Livewire\Tenant\Leads\Index;
use App\Livewire\Tenant\Leads\Show;
use App\Livewire\Tenant\Users\Index as UsersIndex;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Message;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

/**
 * UX-2 — the shared x-confirm-modal Confirm button and the message Resend
 * button carry an explicit `wire:loading.attr="disabled"`, so a second keyboard
 * activation (which Flux's pointer-events-only in-flight guard cannot stop) is
 * blocked and the disabled state is exposed to assistive technology. The bulk
 * "Apply" buttons deliberately get NO such attribute: they sit inside
 * `wire:submit` forms, where Livewire itself disables submit buttons for the
 * duration of the request.
 *
 * A component test cannot run two overlapping browser requests, so these tests
 * lock the rendered contract (the attributes Livewire acts on in the browser)
 * rather than pretending to simulate a double click.
 */

/**
 * The opening tag of the first <button> whose attributes contain $needle.
 */
function buttonTagContaining(string $html, string $needle): string
{
    preg_match_all('/<button\b[^>]*>/s', $html, $matches);

    foreach ($matches[0] as $tag) {
        if (str_contains($tag, $needle)) {
            return $tag;
        }
    }

    throw new RuntimeException("No <button> containing [{$needle}] was rendered.");
}

test('the shared confirm modal disables its Confirm button while its own action is in flight, and keeps its loading target', function (string $variant) {
    $html = Blade::render('<x-confirm-modal wire:model.self="showThing" title="Sure?" confirm-action="thingConfirmed" confirm-label="Do it" variant="'.$variant.'" />');

    $confirm = buttonTagContaining($html, 'wire:click="thingConfirmed"');

    expect($confirm)
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('wire:target="thingConfirmed"')
        ->toContain('type="button"');

    // The spinner markup Flux drives from Livewire's data-loading is still rendered INSIDE the Confirm button itself.
    expect(preg_match('/<button\b[^>]*wire:click="thingConfirmed"[^>]*>.*?<\/button>/s', $html, $confirmElement))->toBe(1);
    expect($confirmElement[0])->toContain('data-flux-loading-indicator')->toContain('Do it');

    // The Cancel button is unchanged: still Flux's own in-flight state, no explicit disabled.
    $cancel = buttonTagContaining($html, "wire:click=\"\$set('showThing', false)\"");
    expect($cancel)->not->toContain('wire:loading.attr="disabled"');
})->with(['danger (default)' => ['danger'], 'primary' => ['primary'], 'filled' => ['filled']]);

test('a real x-confirm-modal usage renders the guarded Confirm button', function () {
    ['clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $html = Livewire::actingAs($clientAdmin)->test(UsersIndex::class)->html();

    expect(buttonTagContaining($html, 'wire:click="deleteConfirmed"'))
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('wire:target="deleteConfirmed"');
});

test('the message Resend button disables itself while resendMessage() is in flight, and keeps its exact action and target', function () {
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
    $failed = Message::factory()->outbound()->withStatus(MessageStatus::Failed)->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'lead_id' => $lead->id,
        'channel' => MessageChannel::Whatsapp,
        'body' => 'Original text',
    ]);

    $html = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->html();

    expect(buttonTagContaining($html, "resendMessage('{$failed->id}')"))
        ->toContain('wire:loading.attr="disabled"')
        ->toMatch("/wire:click=\"resendMessage\\('{$failed->id}'\\)\"/")
        ->toMatch("/wire:target=\"resendMessage\\('{$failed->id}'\\)\"/");
});

test('the bulk action modals keep their wire:submit forms and add no explicit loading attribute to Apply — Livewire disables submit buttons itself', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $html = Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', [$lead->id])
        ->html();

    foreach (['applyBulkStatusChange', 'applyBulkPriorityChange', 'applyBulkTagAction', 'applyBulkAssign'] as $action) {
        expect(preg_match('/<form\b[^>]*wire:submit="'.$action.'"[^>]*>(.*?)<\/form>/s', $html, $form))->toBe(1, "No wire:submit form for {$action}.");

        $apply = buttonTagContaining($form[1], 'type="submit"');

        expect($apply)->not->toContain('wire:loading');
        expect($form[1])->toContain('Apply');
    }
});
