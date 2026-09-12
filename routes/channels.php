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
