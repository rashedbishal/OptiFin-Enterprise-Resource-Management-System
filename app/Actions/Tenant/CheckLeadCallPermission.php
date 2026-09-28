<?php

namespace App\Actions\Tenant;

use App\Enums\LeadCallPermissionStatus;
use App\Models\Lead;
use App\Models\LeadCallPermission;
use App\Models\LeadSource;
use App\Services\WhatsAppCallingClient;
use App\Support\CallingApiResult;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * WhatsApp Outbound Calling — the live, authoritative permission check
 * (`GET .../call_permissions`), run before every consequential action (a
 * permission request, an outbound connect attempt) per the approved
 * architecture: local LeadCallPermission state is bookkeeping/UI only and is
 * never itself the gate.
 *
 * Upserts the local row from the live response so the UI reflects it without
 * a second round-trip, using the same conditional (never-stale-overwriting)
 * write App\Actions\Platform\ManageWhatsAppCalling already established for
 * lead_sources.calling_meta_status: an observation carries the time its
 * request STARTED, and only replaces the row if no newer observation (this
 * check, or a call_permission_reply webhook — see RecordCallPermissionReply)
 * has already landed.
 *
 * Returns the raw CallingApiResult (never collapsed) so a caller can read
 * `actions[]`/`limits[]` live, exactly as the approved contract requires —
 * this action never hardcodes a rate-limit number.
 */
class CheckLeadCallPermission
{
    public function handle(Lead $lead, LeadSource $source): CallingApiResult
    {
        $observedAt = Carbon::now();
        $result = (new WhatsAppCallingClient($source))->checkCallPermission((string) $lead->phone);

        if (! $result->successful) {
            return $result;
        }

        $status = $result->data['status'] ?? null;
        $expiration = $result->data['expiration_time'] ?? null;

        $this->writeObservation($lead, $source, $observedAt, [
            'status' => $this->mapStatus($status),
            'meta_raw_status' => is_string($status) ? $status : null,
            'expires_at' => $status === 'temporary' && is_int($expiration) ? Carbon::createFromTimestamp($expiration) : null,
        ]);

        return $result;
    }

    private function mapStatus(mixed $metaStatus): LeadCallPermissionStatus
    {
        return match ($metaStatus) {
            'temporary' => LeadCallPermissionStatus::Temporary,
            'permanent' => LeadCallPermissionStatus::Permanent,
            default => LeadCallPermissionStatus::NoPermission,
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function writeObservation(Lead $lead, LeadSource $source, Carbon $observedAt, array $attributes): void
    {
        $existing = LeadCallPermission::query()->where('tenant_id', $lead->tenant_id)->where('lead_id', $lead->id)->first();

        if ($existing === null) {
            try {
                LeadCallPermission::query()->create([
                    'tenant_id' => $lead->tenant_id,
                    'lead_id' => $lead->id,
                    'lead_source_id' => $source->id,
                    ...$attributes,
                    'last_synced_at' => $observedAt,
                ]);

                return;
            } catch (UniqueConstraintViolationException) {
                // Lost the race: a concurrent check (this same method) or a
                // call_permission_reply webhook (RecordCallPermissionReply)
                // created the row first, between our own read above and this
                // create(). Re-read it and fall through to the same
                // conditional-update path below, so whichever observation is
                // actually newer still wins — never a second, unguarded write.
                $existing = LeadCallPermission::query()->where('tenant_id', $lead->tenant_id)->where('lead_id', $lead->id)->first();

                if ($existing === null) {
                    return;
                }
            }
        }

        $this->applyUpdate($existing, $source, $observedAt, $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyUpdate(LeadCallPermission $existing, LeadSource $source, Carbon $observedAt, array $attributes): void
    {
        LeadCallPermission::query()
            ->whereKey($existing->id)
            ->where(fn ($query) => $query
                ->whereNull('last_synced_at')
                ->orWhere('last_synced_at', '<=', $observedAt))
            ->update([
                'lead_source_id' => $source->id,
                ...$attributes,
                'last_synced_at' => $observedAt,
                'updated_at' => Carbon::now(),
            ]);
    }
}
