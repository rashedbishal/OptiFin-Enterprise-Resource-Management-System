<?php

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Livewire\Platform\Clients\Show;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadAttachment;
use App\Models\LeadImport;
use App\Models\LeadSource;
use App\Models\Message;
use App\Models\PlatformUser;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Permanently deleting a tenant (framed in the UI as "delete the Client
 * Admin") — Mother Admin only, requires the tenant already deactivated
 * (ManageClientAdminActivation::deactivate()) first, and two separate
 * password confirmations before anything is actually destroyed. See
 * App\Actions\Platform\DeleteTenant's own docblock for the file-cleanup
 * guarantee this covers.
 */

function deactivatedTenantForDeletion(): array
{
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Livewire::actingAs($motherAdmin, 'platform')->test(Show::class, ['tenant' => $tenant])->call('deactivateClientAdmin');

    return ['tenant' => $tenant->fresh(), 'clientAdmin' => $clientAdmin, 'motherAdmin' => $motherAdmin];
}

test('a mother admin can permanently delete a deactivated tenant after two correct passwords', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'motherAdmin' => $motherAdmin] = deactivatedTenantForDeletion();
    $rep = TenantUser::factory()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Show::class, ['tenant' => $tenant])
        ->call('confirmDeleteTenant')
        ->assertSet('showDeleteTenantStep1Modal', true)
        ->set('deleteTenantPassword1', 'password')
        ->call('deleteTenantStep1Confirmed')
        ->assertSet('showDeleteTenantStep1Modal', false)
        ->assertSet('showDeleteTenantStep2Modal', true)
        ->set('deleteTenantPassword2', 'password')
        ->call('deleteTenantStep2Confirmed')
        ->assertRedirect(route('admin.clients.index'));

    expect(Tenant::find($tenant->id))->toBeNull();
    expect(TenantUser::find($clientAdmin->id))->toBeNull();
    expect(TenantUser::find($rep->id))->toBeNull();
    expect(Lead::find($lead->id))->toBeNull();
});

test('an incorrect first password keeps step 1 open and deletes nothing', function () {
    ['tenant' => $tenant, 'motherAdmin' => $motherAdmin] = deactivatedTenantForDeletion();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Show::class, ['tenant' => $tenant])
        ->call('confirmDeleteTenant')
        ->set('deleteTenantPassword1', 'wrong-password')
        ->call('deleteTenantStep1Confirmed')
        ->assertHasErrors(['deleteTenantPassword1'])
        ->assertSet('showDeleteTenantStep1Modal', true)
        ->assertSet('showDeleteTenantStep2Modal', false);

    expect(Tenant::find($tenant->id))->not->toBeNull();
});

test('an incorrect second password keeps the tenant and stays on step 2', function () {
    ['tenant' => $tenant, 'motherAdmin' => $motherAdmin] = deactivatedTenantForDeletion();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Show::class, ['tenant' => $tenant])
        ->call('confirmDeleteTenant')
        ->set('deleteTenantPassword1', 'password')
        ->call('deleteTenantStep1Confirmed')
        ->set('deleteTenantPassword2', 'wrong-password')
        ->call('deleteTenantStep2Confirmed')
        ->assertHasErrors(['deleteTenantPassword2'])
        ->assertSet('showDeleteTenantStep2Modal', true);

    expect(Tenant::find($tenant->id))->not->toBeNull();
});

test('a non-mother platform admin cannot delete a tenant even with correct passwords', function () {
    ['tenant' => $tenant] = deactivatedTenantForDeletion();
    $restrictedAdmin = PlatformUser::factory()->create();

    // Same established pattern as PlatformLeadSourceDeletionTest/
    // ClientAdminActivationTest: a restricted admin fails mount()'s own
    // 'view' gate first, so testing the ability directly avoids the
    // Livewire-testing quirk around a mount-time authorization failure.
    expect(Gate::forUser($restrictedAdmin)->denies('delete', $tenant))->toBeTrue();

    expect(Tenant::find($tenant->id))->not->toBeNull();
});

test('deletion is refused while the client admin is still active', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Show::class, ['tenant' => $tenant])
        ->call('confirmDeleteTenant')
        ->assertSet('showDeleteTenantStep1Modal', false);

    expect($clientAdmin->refresh()->is_active)->toBeTrue();
    expect(Tenant::find($tenant->id))->not->toBeNull();
});

test('deletion physically removes lead attachments, outbound message media, and csv import files from storage', function () {
    Storage::fake('local');

    ['tenant' => $tenant, 'motherAdmin' => $motherAdmin] = deactivatedTenantForDeletion();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id]);

    $attachment = LeadAttachment::factory()->create(['lead_id' => $lead->id, 'path' => 'leads/attachment.jpg']);
    Storage::disk('local')->put($attachment->path, 'fake-image-bytes');

    $message = Message::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'lead_id' => $lead->id,
        'channel' => MessageChannel::Whatsapp,
        'direction' => MessageDirection::Outbound,
        'message_type' => MessageType::Image,
        'attachment_path' => 'outbound/media.jpg',
    ]);
    Storage::disk('local')->put($message->attachment_path, 'fake-outbound-bytes');

    $import = LeadImport::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'file_path' => 'lead-imports/import.csv']);
    Storage::disk('local')->put($import->file_path, 'fake,csv,bytes');

    $tenant->update(['whatsapp_call_ringtone_path' => 'ringtones/custom.mp3']);
    Storage::disk('local')->put('ringtones/custom.mp3', 'fake-ringtone-bytes');

    Storage::disk('local')->assertExists([$attachment->path, $message->attachment_path, $import->file_path, 'ringtones/custom.mp3']);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Show::class, ['tenant' => $tenant->fresh()])
        ->call('confirmDeleteTenant')
        ->set('deleteTenantPassword1', 'password')
        ->call('deleteTenantStep1Confirmed')
        ->set('deleteTenantPassword2', 'password')
        ->call('deleteTenantStep2Confirmed');

    Storage::disk('local')->assertMissing($attachment->path);
    Storage::disk('local')->assertMissing($message->attachment_path);
    Storage::disk('local')->assertMissing($import->file_path);
    Storage::disk('local')->assertMissing('ringtones/custom.mp3');
});

test('the delete button only appears for a mother admin once the client admin is deactivated', function () {
    ['tenant' => $tenant, 'motherAdmin' => $motherAdmin] = deactivatedTenantForDeletion();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Show::class, ['tenant' => $tenant])
        ->assertSeeHtml('wire:click="confirmDeleteTenant"');

    ['tenant' => $activeTenant] = tenantWithLeadManagement();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Show::class, ['tenant' => $activeTenant])
        ->assertDontSeeHtml('wire:click="confirmDeleteTenant"');
});
