<?php

namespace App\Models;

use Database\Factories\LeadConversationDeletionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only audit row written by App\Actions\Tenant\DeleteLeadConversation,
 * inside the same transaction as the deletion itself, so a wiped
 * conversation always has a record (fail-closed). Holds who/when/which lead
 * and channel plus a count-per-kind summary — never any message content.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $lead_id
 * @property string $lead_identifier
 * @property string $lead_name
 * @property string $channel
 * @property string|null $deleted_by
 * @property array<string, int>|null $summary
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Lead|null $lead
 * @property-read TenantUser|null $deletedBy
 */
#[Fillable(['tenant_id', 'lead_id', 'lead_identifier', 'lead_name', 'channel', 'deleted_by', 'summary'])]
class LeadConversationDeletion extends Model
{
    /** @use HasFactory<LeadConversationDeletionFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['summary' => 'array'];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<TenantUser, $this>
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'deleted_by');
    }
}
