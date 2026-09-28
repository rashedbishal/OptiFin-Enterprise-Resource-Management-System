<?php

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\LeadCallPermissionStatus;
use App\Enums\MessageChannel;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\LeadCallPermission;
use App\Models\LeadSource;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * WhatsApp Outbound Calling — the Lead detail page's outbound-call panel:
 * the permission badge/actions and the calls-card's direction-aware
 * rendering. Meta is always faked; nothing here hits the network.
 */

function ocuiScenario(): array
{
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $source = Wa::source($tenant, [], ['access_token' => 'OCUI-sentinel-token']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'phone' => Wa::CALLER]);

    return ['tenant' => $tenant, 'admin' => $admin, 'source' => $source, 'lead' => $lead];
}

test('the outbound-call panel does not render for a lead with no WhatsApp source', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $otherSource = LeadSource::factory()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $otherSource->id]);

    Livewire::actingAs($admin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->assertDontSeeHtml('data-test="outbound-call-panel"');
});

test('the Calls card renders the Request Permission button for a non-WhatsApp lead that has a WhatsApp conversation and no calls yet', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $otherSource = LeadSource::factory()->create(['tenant_id' => $tenant->id]);
    $waSource = Wa::source($tenant, [], ['access_token' => 'OCUI-conversation-token']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $otherSource->id, 'phone' => Wa::CALLER]);
    Conversation::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $waSource->id, 'channel' => MessageChannel::Whatsapp]);

    expect(LeadCall::where('lead_id', $lead->id)->count())->toBe(0);

    Livewire::actingAs($admin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->assertSeeHtml('data-test="lead-calls-card"')
        ->assertSeeHtml('data-test="outbound-call-panel"')
        ->assertSeeHtml('data-test="request-call-permission-button"');
});

test('a WhatsApp lead with no permission record shows "permission required" and no Call button', function () {
    $x = ocuiScenario();

    Livewire::actingAs($x['admin'])->test(Show::class, ['lead' => $x['lead']])->call('completeInitialLoad')
        ->assertSeeHtml('data-test="outbound-call-panel"')
        ->assertSee('Calling permission required')
        ->assertDontSeeHtml('data-test="outbound-call-button"');
});

test('an effectively-granted permission shows the Call button, not the request button', function () {
    $x = ocuiScenario();
    LeadCallPermission::factory()->permanent()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id]);

    Livewire::actingAs($x['admin'])->test(Show::class, ['lead' => $x['lead']])->call('completeInitialLoad')
        ->assertSee('Call permission granted')
        ->assertSeeHtml('data-test="outbound-call-button"')
        ->assertDontSeeHtml('data-test="request-call-permission-button"');
});

test('a requested permission shows "pending"', function () {
    $x = ocuiScenario();
    LeadCallPermission::factory()->requested()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id]);

    Livewire::actingAs($x['admin'])->test(Show::class, ['lead' => $x['lead']])->call('completeInitialLoad')
        ->assertSee('Permission request pending');
});

test('a rejected permission shows "previously declined" and still offers Request permission again', function () {
    $x = ocuiScenario();
    LeadCallPermission::factory()->rejected()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id]);

    Livewire::actingAs($x['admin'])->test(Show::class, ['lead' => $x['lead']])->call('completeInitialLoad')
        ->assertSee('Permission previously declined')
        ->assertSeeHtml('data-test="request-call-permission-button"');
});

test('checkCallPermission() live-checks Meta and updates the shown state', function () {
    $x = ocuiScenario();
    Http::fake(['*/call_permissions*' => Http::response(['permission' => ['status' => 'permanent']])]);

    Livewire::actingAs($x['admin'])->test(Show::class, ['lead' => $x['lead']])->call('completeInitialLoad')
        ->call('checkCallPermission')
        ->assertSee('Call permission granted');

    expect(LeadCallPermission::where('lead_id', $x['lead']->id)->sole()->status)->toBe(LeadCallPermissionStatus::Permanent);
});

test('requestCallPermission() sends the template when Meta live-allows it, and toasts success', function () {
    $x = ocuiScenario();
    Http::fake([
        '*/call_permissions*' => Http::response(['permission' => ['status' => 'no_permission'], 'actions' => [
            ['action_name' => 'send_call_permission_request', 'can_perform_action' => true, 'limits' => []],
        ]]),
        '*/messages' => Http::response(['messages' => [['id' => 'wamid.PERM-UI-1']]]),
    ]);

    Livewire::actingAs($x['admin'])->test(Show::class, ['lead' => $x['lead']])->call('completeInitialLoad')
        ->call('requestCallPermission')
        ->assertSee('Permission request pending');

    expect(LeadCallPermission::where('lead_id', $x['lead']->id)->sole()->status)->toBe(LeadCallPermissionStatus::Requested);
});

test('requestCallPermission() refuses quietly (no template sent) when Meta live-reports it is not allowed', function () {
    $x = ocuiScenario();
    Http::fake(['*/call_permissions*' => Http::response(['permission' => ['status' => 'no_permission'], 'actions' => [
        ['action_name' => 'send_call_permission_request', 'can_perform_action' => false, 'limits' => []],
    ]])]);

    Livewire::actingAs($x['admin'])->test(Show::class, ['lead' => $x['lead']])->call('completeInitialLoad')
        ->call('requestCallPermission');

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/messages'));
    expect(LeadCallPermission::where('lead_id', $x['lead']->id)->sole()->status)->toBe(LeadCallPermissionStatus::NoPermission);
});

test('a user without lead-management update permission cannot check or request permission', function () {
    $x = ocuiScenario();
    // Assigned to the lead (so LeadPolicy::view() succeeds and the page
    // itself loads) but only holding 'view', never 'update' — the exact
    // permission checkCallPermission()/requestCallPermission() require.
    $viewOnly = clientUserWithLeadPermission($x['tenant'], ['view']);
    $x['lead']->update(['assigned_to' => $viewOnly->id]);
    Http::fake(['*' => Http::response(['permission' => ['status' => 'permanent']])]);

    Livewire::actingAs($viewOnly)->test(Show::class, ['lead' => $x['lead']])->call('completeInitialLoad')
        ->call('checkCallPermission');

    Http::assertNothingSent();
    expect(LeadCallPermission::count())->toBe(0);
});

test('the calls card shows Outgoing/Called by for an outbound row and Incoming/Answered by for inbound, on the same lead', function () {
    $x = ocuiScenario();
    LeadCall::factory()->create([
        'tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id,
        'direction' => CallDirection::Outbound, 'status' => CallStatus::Completed,
        'claimed_by' => $x['admin']->id, 'routed_user_ids' => [$x['admin']->id],
    ]);
    LeadCall::factory()->create([
        'tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id,
        'direction' => CallDirection::Inbound, 'status' => CallStatus::Completed,
        'claimed_by' => $x['admin']->id, 'routed_user_ids' => [$x['admin']->id],
    ]);

    Livewire::actingAs($x['admin'])->test(Show::class, ['lead' => $x['lead']])->call('completeInitialLoad')
        ->assertSee('Outgoing')
        ->assertSee('Called by: '.$x['admin']->name)
        ->assertSee('Incoming')
        ->assertSee('Answered by: '.$x['admin']->name);
});
