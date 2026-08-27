<?php

use App\Models\Lead;
use App\Models\TenantUser;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

Broadcast::channel('tenant.{tenantId}', function (TenantUser $user, string $tenantId) {
    return $user->tenant_id === $tenantId;
});

Broadcast::channel('lead.{lead}', function (TenantUser $user, Lead $lead) {
    return Gate::forUser($user)->allows('view', $lead);
});
