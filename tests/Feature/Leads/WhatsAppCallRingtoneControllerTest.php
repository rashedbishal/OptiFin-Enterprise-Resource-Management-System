<?php

use Illuminate\Support\Facades\Storage;

/*
 * WhatsApp inbound calling — serves a tenant's custom ringtone inline to the
 * softphone's <audio> element. Mirrors LeadAttachmentController's own
 * tenant-isolation test shape (own tenant can fetch it, another tenant
 * cannot, missing file 404s).
 */

beforeEach(fn () => Storage::fake('local'));

test('a tenant user can fetch their own tenant ringtone inline', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Storage::disk('local')->put('whatsapp-call-ringtones/test.mp3', 'fake-mp3-bytes');
    $tenant->update([
        'whatsapp_call_ringtone_path' => 'whatsapp-call-ringtones/test.mp3',
        'whatsapp_call_ringtone_original_name' => 'test.mp3',
        'whatsapp_call_ringtone_size' => 14,
    ]);

    $this->actingAs($clientAdmin)
        ->get(route('tenant.whatsapp-call-ringtone'))
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/mpeg');
});

test('a tenant with no ringtone set gets a 404', function () {
    ['clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $this->actingAs($clientAdmin)
        ->get(route('tenant.whatsapp-call-ringtone'))
        ->assertNotFound();
});

test('a user cannot fetch another tenant ringtone', function () {
    ['tenant' => $tenantA] = tenantWithLeadManagement();
    ['clientAdmin' => $clientAdminB] = tenantWithLeadManagement();

    Storage::disk('local')->put('whatsapp-call-ringtones/a.mp3', 'fake-mp3-bytes');
    $tenantA->update([
        'whatsapp_call_ringtone_path' => 'whatsapp-call-ringtones/a.mp3',
        'whatsapp_call_ringtone_original_name' => 'a.mp3',
        'whatsapp_call_ringtone_size' => 14,
    ]);

    // clientAdminB's own tenant has no ringtone, so this must 404 for them
    // regardless of tenant A's file existing — the controller only ever
    // reads Auth::user()->tenant, never a client-suppliable id.
    $this->actingAs($clientAdminB)
        ->get(route('tenant.whatsapp-call-ringtone'))
        ->assertNotFound();
});

test('guests are redirected to login', function () {
    $this->get(route('tenant.whatsapp-call-ringtone'))
        ->assertRedirect(route('login'));
});
