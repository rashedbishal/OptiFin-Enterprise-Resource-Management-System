<?php

namespace App\Models;

use App\Enums\CallDirection;
use App\Enums\CallEndReason;
use App\Enums\CallMetaStage;
use App\Enums\CallRoutingMode;
use App\Enums\CallStatus;
use Database\Factories\LeadCallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * WhatsApp inbound calling (Phase 1) — one row per Meta call id (`wacid.…`),
 * unique per lead source. A plain lead_id-keyed child of Lead: added to
 * LeadMergeService::CHILD_MODELS, cascade-deleted with its Lead (which is
 * refused while a call is still active — see LeadDeletionEligibilityService).
 * No BelongsToTenant trait — same discipline as Conversation/Message: every
 * query filters tenant_id explicitly, since webhook processing has no
 * authenticated tenant to scope by.
 *
 * `routed_user_ids`/`routing_mode`/`assignee_id_at_arrival` are the routing
 * snapshot frozen at arrival; a later reassignment of the lead never changes
 * them, and `routed_user_ids` is the authorization list for the live call.
 * `claimed_by` is the agent who won the atomic claim (set at claim time, so
 * "who actually spoke" is claimed_by only once `connected_at` is set).
 * The SDP offer/answer is never stored on this row — see the architecture
 * (offer lives in the cache, briefly).
 *
 * WhatsApp Outbound Calling: `direction` defaults `inbound` for every
 * pre-existing and inbound-webhook-created row. An outbound row (created
 * only by App\Actions\Tenant\InitiateOutboundCall) may exist with
 * `external_call_id` still NULL — created before Meta's synchronous /calls
 * response returns the wacid (status Initiated) — never true for an inbound
 * row. `biz_opaque_callback_data` is the server-minted correlation token
 * used for that brief window and, after, as a defense-in-depth secondary
 * check; never accepted from the browser.
 *
 * `consumer_accepted_at` — set exactly once, only by
 * App\Actions\Tenant\RecordOutboundCallStatusEvent on Meta's own `ACCEPTED`
 * call-status webhook. It is a second, independent precondition
 * App\Actions\Tenant\ConfirmOutboundCallConnected requires alongside the
 * browser's own real WebRTC/ICE confirmation before Answering -> Connected —
 * Meta's earlier `connect` webhook (the SDP answer) is not evidence the
 * consumer answered, and neither is the browser's ICE state alone (that only
 * proves the browser reached Meta's own media relay). Always NULL for an
 * inbound row.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $lead_id
 * @property string $lead_source_id
 * @property string|null $conversation_id
 * @property CallDirection $direction
 * @property string|null $external_call_id
 * @property string|null $biz_opaque_callback_data
 * @property CallStatus $status
 * @property CallEndReason|null $end_reason
 * @property CallMetaStage|null $meta_stage
 * @property string|null $meta_status
 * @property Carbon|null $meta_terminated_at
 * @property string|null $assignee_id_at_arrival
 * @property CallRoutingMode $routing_mode
 * @property array<int, string> $routed_user_ids
 * @property string|null $claimed_by
 * @property Carbon|null $claimed_at
 * @property Carbon|null $connected_at
 * @property Carbon|null $consumer_accepted_at
 * @property Carbon|null $ended_at
 * @property int|null $duration_seconds
 * @property Carbon|null $ringing_expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lead $lead
 * @property-read LeadSource $leadSource
 * @property-read Conversation|null $conversation
 * @property-read TenantUser|null $claimedBy
 * @property-read TenantUser|null $assigneeAtArrival
 */
#[Fillable([
    'tenant_id', 'lead_id', 'lead_source_id', 'conversation_id', 'direction', 'external_call_id', 'biz_opaque_callback_data',
    'status', 'end_reason', 'meta_stage', 'meta_status', 'meta_terminated_at',
    'assignee_id_at_arrival', 'routing_mode', 'routed_user_ids',
    'claimed_by', 'claimed_at', 'connected_at', 'consumer_accepted_at', 'ended_at', 'duration_seconds', 'ringing_expires_at',
])]
class LeadCall extends Model
{
    /** @use HasFactory<LeadCallFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => CallDirection::class,
            'status' => CallStatus::class,
            'end_reason' => CallEndReason::class,
            'meta_stage' => CallMetaStage::class,
            'routing_mode' => CallRoutingMode::class,
            'routed_user_ids' => 'array',
            'meta_terminated_at' => 'datetime',
            'claimed_at' => 'datetime',
            'connected_at' => 'datetime',
            'consumer_accepted_at' => 'datetime',
            'ended_at' => 'datetime',
            'ringing_expires_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    /**
     * Calls still in flight (Ringing/Answering/Connected).
     *
     * @param  Builder<LeadCall>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereIn('status', CallStatus::activeValues());
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<LeadSource, $this>
     */
    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<TenantUser, $this>
     */
    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'claimed_by');
    }

    /**
     * @return BelongsTo<TenantUser, $this>
     */
    public function assigneeAtArrival(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'assignee_id_at_arrival');
    }
}
