<?php

use App\Models\Lead;
use App\Models\LeadAttachment;
use Illuminate\Support\Facades\Storage;

test('a client admin can download their tenant\'s lead attachment', function () {
    Storage::fake('local');
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $attachment = LeadAttachment::factory()->create([
        'lead_id' => $lead->id,
        'path' => "leads/{$tenant->tenant_code}/messenger/psid-1_123.jpg",
        'type' => 'image',
        'mime' => 'image/jpeg',
    ]);
    Storage::disk('local')->put($attachment->path, 'fake-image-bytes');

    $this->actingAs($clientAdmin)
        ->get(route('tenant-leads.attachment.download', [$lead, $attachment]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

test('a nonexistent attachment returns not found', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($clientAdmin)
        ->get(route('tenant-leads.attachment.download', [$lead, 'does-not-exist']))
        ->assertNotFound();
});

test('an attachment that belongs to a different lead returns not found', function () {
    Storage::fake('local');
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $otherLead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $attachment = LeadAttachment::factory()->create(['lead_id' => $otherLead->id]);
    Storage::disk('local')->put($attachment->path, 'fake-image-bytes');

    $this->actingAs($clientAdmin)
        ->get(route('tenant-leads.attachment.download', [$lead, $attachment]))
        ->assertNotFound();
});

test('a user from another tenant cannot reach a lead\'s attachment', function () {
    Storage::fake('local');
    ['tenant' => $tenantA] = tenantWithLeadManagement();
    ['clientAdmin' => $clientAdminB] = tenantWithLeadManagement();

    $lead = Lead::factory()->create(['tenant_id' => $tenantA->id]);
    $attachment = LeadAttachment::factory()->create([
        'lead_id' => $lead->id,
        'path' => "leads/{$tenantA->tenant_code}/messenger/psid-1_123.jpg",
    ]);
    Storage::disk('local')->put($attachment->path, 'fake-image-bytes');

    $this->actingAs($clientAdminB)
        ->get(route('tenant-leads.attachment.download', [$lead, $attachment]))
        ->assertForbidden();
});

test('a client user without view permission cannot download an unassigned lead\'s attachment', function () {
    Storage::fake('local');
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $clientUser = clientUserWithLeadPermission($tenant, []);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $attachment = LeadAttachment::factory()->create([
        'lead_id' => $lead->id,
        'path' => "leads/{$tenant->tenant_code}/messenger/psid-1_123.jpg",
    ]);
    Storage::disk('local')->put($attachment->path, 'fake-image-bytes');

    $this->actingAs($clientUser)
        ->get(route('tenant-leads.attachment.download', [$lead, $attachment]))
        ->assertForbidden();
});
