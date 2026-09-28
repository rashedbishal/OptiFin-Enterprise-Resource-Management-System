<?php

namespace App\Actions\Tenant;

use App\Enums\ConsentSource;
use App\Enums\ConsentStatus;
use App\Enums\ConsentType;
use App\Models\Lead;
use App\Models\LeadConsent;
use App\Models\LeadConsentEvent;
use Illuminate\Database\UniqueConstraintViolationException;
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

            // Race-safe equivalent of updateOrCreate(['lead_id' => ...,
            // 'consent_type' => ...], $attributes): a plain updateOrCreate()
            // has no recovery when two concurrent callers (Grant/Withdraw,
            // a Website Form submission, or two admins) both see no
            // existing row and both attempt create() — the loser's INSERT
            // hits the real unique(lead_id, consent_type) constraint
            // uncaught. This preserves the exact same "whichever write
            // lands last wins" semantics as before (never a freshness/
            // timestamp comparison, unlike LeadCallPermission's writers) —
            // only the create-then-recover shape is new.
            $consent = LeadConsent::query()->where('lead_id', $lead->id)->where('consent_type', $type)->first();

            if ($consent === null) {
                try {
                    // withSavepointIfNeeded() equivalent: this whole method
                    // already runs inside the outer DB::transaction() above
                    // (transactionLevel() > 0), so a nested DB::transaction()
                    // here is a SAVEPOINT, not a second real transaction —
                    // critical on Postgres, where an uncaught constraint
                    // violation otherwise leaves the OUTER transaction
                    // aborted even after this catch, failing every
                    // statement after it (the recovery re-read below, and
                    // the LeadConsentEvent::create() after this whole
                    // if/else) with "current transaction is aborted".
                    $consent = DB::transaction(fn () => LeadConsent::query()->create($attributes));
                } catch (UniqueConstraintViolationException) {
                    // Lost the race: a concurrent call for this same
                    // lead+type created the row first, between our own read
                    // above and this create(). Re-read it and fall through
                    // to the same unconditional update every call below
                    // already makes — this call's own attributes still win
                    // if it's genuinely the last write to land.
                    $consent = LeadConsent::query()->where('lead_id', $lead->id)->where('consent_type', $type)->firstOrFail();
                    $consent->update($attributes);
                }
            } else {
                $consent->update($attributes);
            }

            LeadConsentEvent::create($attributes);

            return $consent;
        });
    }
}
