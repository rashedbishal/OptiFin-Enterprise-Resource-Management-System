<?php

namespace App\Actions\Tenant;

use App\Enums\ConsentSource;
use App\Enums\ConsentStatus;
use App\Enums\ConsentType;
use App\Models\Lead;
use App\Models\LeadConsent;
use App\Models\LeadConsentEvent;
use Illuminate\Support\Facades\DB;

/**
 * Feature #14 — the single "update/create current state + append exactly
 * one history event" primitive every consent-mutating caller goes through
 * (the public Website Form submission path and the manual Lead-detail Grant/
 * Withdraw actions alike) — never duplicated logic across the two paths,
 * same shared-primitive discipline App\Actions\Tenant\SaveLeadCustomFieldValues
 * already established for Feature #11.
 *
 * Both writes happen inside one DB transaction: either the LeadConsent
 * upsert and the new LeadConsentEvent row both persist, or neither does — a
 * partial success (current state updated but no history row, or vice versa)
 * must never be possible.
 */
class RecordLeadConsent
{
    public function handle(
        Lead $lead,
        ConsentType $type,
        ConsentStatus $status,
        ConsentSource $source,
        ?string $changedBy = null,
        ?string $websiteFormId = null,
        ?string $websiteFormName = null,
        ?string $policyVersion = null,
        ?string $policyUrl = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): LeadConsent {
        return DB::transaction(function () use ($lead, $type, $status, $source, $changedBy, $websiteFormId, $websiteFormName, $policyVersion, $policyUrl, $ipAddress, $userAgent): LeadConsent {
            $attributes = [
                'tenant_id' => $lead->tenant_id,
                'lead_id' => $lead->id,
                'consent_type' => $type,
                'status' => $status,
                'source' => $source,
                'website_form_id' => $websiteFormId,
                'website_form_name' => $websiteFormName,
                'changed_by' => $changedBy,
                'policy_version' => $policyVersion,
                'policy_url' => $policyUrl,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'occurred_at' => now(),
            ];

            $consent = LeadConsent::updateOrCreate(
                ['lead_id' => $lead->id, 'consent_type' => $type],
                $attributes,
            );

            LeadConsentEvent::create($attributes);

            return $consent;
        });
    }
}
