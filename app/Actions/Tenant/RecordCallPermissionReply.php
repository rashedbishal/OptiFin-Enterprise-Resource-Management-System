<?php

namespace App\Actions\Tenant;

use App\Enums\LeadCallPermissionStatus;
use App\Models\Lead;
use App\Models\LeadCallPermission;
use App\Models\LeadSource;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * WhatsApp Outbound Calling — the single writer of a `call_permission_reply`
 * webhook item (interactive.type === 'call_permission_reply' on the
 * EXISTING messages[] webhook — Meta does not deliver this through calls[]).
 *
 * Correlation is deliberately phone/source-based, not per-call: the
 * verified contract gives this webhook no call-specific id (no wacid,
 * no reliable per-call biz_opaque_callback_data), and permission is
 * inherently a property of (business number, consumer), not of any one
 * call attempt — matching exactly what GET .../call_permissions itself
 * takes (phone_number_id + consumer identity, nothing else). The caller
 * (LeadWebhookController) resolves the Lead the same way the existing
 * inbound message path already does (conversation-first, then phone-on-
 * source) — this action is never given a bare phone number to resolve
 * itself, and is simply never invoked when no Lead matches (an unknown
 * consumer's reply is logged and dropped, never creates a Lead — same
 * discipline RecordInboundCall already applies to a terminate webhook).
 *
 * Idempotent and reply-order-safe: applies a conditional (never-stale-
 * overwriting) write using the webhook's own event time, the same pattern
 * CheckLeadCallPermission already uses for the live-check path — whichever
 * of the two sources of truth (a live check, or this reply) is NEWER always
 * wins, regardless of arrival order.
 */
class RecordCallPermissionReply
{
    public function handle(Lead $lead, LeadSource $source, string $response, bool $isPermanent, ?int $expirationTimestamp, Carbon $observedAt): void
    {
        $status = $this->mapStatus($response, $isPermanent);
        $expiresAt = $status === LeadCallPermissionStatus::Temporary && $expirationTimestamp !== null
            ? Carbon::createFromTimestamp($expirationTimestamp)
            : null;

        $existing = LeadCallPermission::query()->where('tenant_id', $lead->tenant_id)->where('lead_id', $lead->id)->first();

        if ($existing === null) {
            try {
                LeadCallPermission::query()->create([
                    'tenant_id' => $lead->tenant_id,
                    'lead_id' => $lead->id,
                    'lead_source_id' => $source->id,
                    'status' => $status,
                    'meta_raw_status' => null,
                    'expires_at' => $expiresAt,
                    'last_synced_at' => $observedAt,
                ]);

                return;
            } catch (UniqueConstraintViolationException) {
                // Lost the race: a concurrent call_permission_reply (this
                // same method) or a live CheckLeadCallPermission check
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

        LeadCallPermission::query()
            ->whereKey($existing->id)
            ->where(fn ($query) => $query
                ->whereNull('last_synced_at')
                ->orWhere('last_synced_at', '<=', $observedAt))
            ->update([
                'lead_source_id' => $source->id,
                'status' => $status->value,
                'expires_at' => $expiresAt,
                'last_synced_at' => $observedAt,
                'updated_at' => Carbon::now(),
            ]);
    }

    private function mapStatus(string $response, bool $isPermanent): LeadCallPermissionStatus
    {
        if ($response !== 'accept') {
            return LeadCallPermissionStatus::Rejected;
        }

        return $isPermanent ? LeadCallPermissionStatus::Permanent : LeadCallPermissionStatus::Temporary;
    }
}
