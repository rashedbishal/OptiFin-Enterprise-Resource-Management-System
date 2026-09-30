<?php

use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\TenantUser;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

Broadcast::channel('tenant.{tenantId}', function (TenantUser $user, string $tenantId) {
    return $user->tenant_id === $tenantId;
});

Broadcast::channel('lead.{lead}', function (TenantUser $user, Lead $lead) {
    return Gate::forUser($user)->allows('view', $lead);
});

// Same authorization bar as App\Livewire\Tenant\LeadImports\Show::mount():
// Gate::authorize('create', Lead::class) + explicit tenant_id match, since
// LeadImport has no dedicated policy and CSV import reuses Lead's "create"
// ability rather than a new permission slug.
Broadcast::channel('lead-import.{leadImport}', function (TenantUser $user, LeadImport $leadImport) {
    return $user->tenant_id === $leadImport->tenant_id
        && Gate::forUser($user)->allows('create', Lead::class);
});

Broadcast::channel('tenant-user.{tenantUserId}', function (TenantUser $user, string $tenantUserId) {
    return (string) $user->id === $tenantUserId;
});

// WhatsApp Call Backup / Overflow Receiver, Phase 2 — a presence channel
// (wire name presence-tenant-softphone.{tenantId}, per
// Illuminate\Broadcasting\PresenceChannel's own "presence-" prefixing), so
// App\Services\ReverbPresenceQuery can ask Reverb's HTTP API who is
// currently a member. Additive: the pre-existing tenant-user.{id} private
// channel above is untouched and still carries .call.updated — this one
// exists purely to establish membership, never to carry an event. Returning
// an array (rather than a bool, like every other channel here) is what
// makes this a presence channel; only the id is exposed — no name, no
// role, no permission, nothing a member list could leak beyond "this user
// is online."
Broadcast::channel('tenant-softphone.{tenantId}', function (TenantUser $user, string $tenantId) {
    if ($user->tenant_id !== $tenantId) {
        return false;
    }

    return ['id' => $user->id];
});
