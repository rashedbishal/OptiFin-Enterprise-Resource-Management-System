<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\LeadPriority;
use App\Enums\LeadSourceType;
use Carbon\CarbonInterface;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $source_id
 * @property string|null $assigned_to
 * @property string $status_id
 * @property string $name
 * @property string|null $company
 * @property string|null $website
 * @property string|null $industry
 * @property string|null $location
 * @property string|null $service_interest
 * @property string|null $lead_source
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $message
 * @property string|null $html_body
 * @property array<string, mixed>|null $source_data
 * @property-read LeadStatus $status
 * @property LeadPriority $priority
 * @property string|null $notes
 * @property string|null $estimated_budget
 * @property string|null $deal_value
 * @property int|null $win_probability
 * @property Carbon|null $next_follow_up_at
 * @property Carbon|null $meeting_date
 * @property Carbon|null $assigned_at
 * @property Carbon|null $first_contacted_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 */
#[Fillable([
    'tenant_id', 'source_id', 'assigned_to', 'name', 'company', 'website', 'industry', 'location',
    'service_interest', 'lead_source', 'email', 'phone', 'message', 'html_body', 'source_data', 'status_id', 'priority',
    'notes', 'estimated_budget', 'deal_value', 'win_probability', 'next_follow_up_at', 'meeting_date',
    'assigned_at', 'first_contacted_at', 'closed_at',
])]
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_data' => 'array',
            'priority' => LeadPriority::class,
            'estimated_budget' => 'decimal:2',
            'deal_value' => 'decimal:2',
            'win_probability' => 'integer',
            'next_follow_up_at' => 'date',
            'meeting_date' => 'date',
            'assigned_at' => 'datetime',
            'first_contacted_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * The sender's profile picture, for Messenger/Instagram leads — captured
     * alongside their name when the webhook's Graph API profile lookup
     * succeeds. Not a column: it's already in `source_data`, and duplicating
     * it into its own column would just be another place for the two to
     * drift out of sync.
     */
    public function avatarUrl(): ?string
    {
        $data = $this->source_data ?? [];
        $url = $data['facebook_profile_pic'] ?? $data['instagram_profile_pic'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Maps this lead's raw Facebook Lead Ads `field_data` payload (as Meta's
     * Graph API returns it: `[{name, values: [...]}, ...]`) into a flat
     * `name => first value` array, for rendering the per-source spreadsheet
     * view's dynamic columns. Not derived from `name`/`email`/`phone`
     * because those are this app's best-effort mapping of a few known field
     * name variants (see `LeadWebhookController::mapLeadgenFieldData()`),
     * while the spreadsheet needs every field the form actually asked,
     * under its own name.
     *
     * @return array<string, string>
     */
    public function leadAdFieldValues(): array
    {
        $fieldData = $this->source_data['field_data'] ?? null;

        if (! is_array($fieldData)) {
            return [];
        }

        $values = [];

        foreach ($fieldData as $field) {
            $name = $field['name'] ?? null;
            $value = $field['values'][0] ?? null;

            if (is_string($name) && $name !== '' && is_string($value)) {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    /**
     * @return HasMany<LeadAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(LeadAttachment::class)->orderBy('sent_at');
    }

    /**
     * Reconstructs the individual text messages that make up this lead's
     * conversation, in arrival order, from the raw per-message webhook
     * payloads stored in `source_data['messages']` — `message` alone only
     * holds the flattened, newline-joined text (kept as-is for previews and
     * notifications). Used to interleave text with attachments on the lead
     * detail page, since a thread can carry several of each over time.
     *
     * @return array<int, array{text: string, at: CarbonInterface}>
     */
    public function messageTimeline(): array
    {
        $entries = $this->source_data['messages'] ?? null;

        if (! is_array($entries)) {
            return [];
        }

        $isWhatsapp = $this->source->type === LeadSourceType::Whatsapp;

        return collect($entries)
            ->map(fn (array $entry): array => $isWhatsapp
                ? $this->parseWhatsappTimelineEntry($entry)
                : $this->parseMessagingTimelineEntry($entry))
            ->filter(fn (array $entry): bool => $entry['text'] !== null)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{text: ?string, at: CarbonInterface}
     */
    private function parseMessagingTimelineEntry(array $entry): array
    {
        $text = $entry['message']['text'] ?? null;

        return [
            'text' => is_string($text) && $text !== '' ? $text : null,
            'at' => isset($entry['timestamp']) ? Carbon::createFromTimestampMs((int) $entry['timestamp']) : ($this->created_at ?? now()),
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{text: ?string, at: CarbonInterface}
     */
    private function parseWhatsappTimelineEntry(array $entry): array
    {
        $type = $entry['type'] ?? 'text';
        $text = $entry['text']['body'] ?? ($entry[$type]['caption'] ?? null);

        return [
            'text' => is_string($text) && $text !== '' ? $text : null,
            'at' => isset($entry['timestamp']) ? Carbon::createFromTimestamp((int) $entry['timestamp']) : ($this->created_at ?? now()),
        ];
    }

    /**
     * @return BelongsTo<LeadSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    /**
     * @return BelongsTo<LeadStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'status_id');
    }

    /**
     * The next stage in this lead's tenant's configured pipeline, for the
     * Kanban board's one-click "advance" action — replaces the old fixed
     * enum's nextInPipeline(), since "next" now depends on a specific
     * tenant's chosen order rather than a single hardcoded sequence. A
     * status the admin marked closed (e.g. Won/Lost) has none, even if it
     * isn't last in the tenant's list — reached by a deliberate choice, not
     * a "next" step.
     */
    public function nextPipelineStatus(): ?LeadStatus
    {
        if ($this->status->is_closed) {
            return null;
        }

        $pipeline = $this->tenant->leadStatuses;
        $currentIndex = $pipeline->search(fn (LeadStatus $status): bool => $status->id === $this->status_id);

        if ($currentIndex === false) {
            return null;
        }

        return $pipeline->get($currentIndex + 1);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'assigned_to');
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(LeadStatusLog::class);
    }

    public function detailUpdateLogs(): HasMany
    {
        return $this->hasMany(LeadDetailUpdateLog::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LeadAssignment::class);
    }

    public function leadNotes(): HasMany
    {
        return $this->hasMany(LeadNote::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function assignedTo(Builder $query, string $tenantUserId): void
    {
        $query->where('assigned_to', $tenantUserId);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function unassigned(Builder $query): void
    {
        $query->whereNull('assigned_to');
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function byStatus(Builder $query, string $statusId): void
    {
        $query->where('status_id', $statusId);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function byPriority(Builder $query, LeadPriority $priority): void
    {
        $query->where('priority', $priority);
    }

    /**
     * Get lead counts grouped by status, zero-filled for statuses with no
     * leads. `$tenantStatuses` is the tenant's configured pipeline (see
     * Tenant::leadStatuses()) — counts are only ever zero-filled for
     * statuses actually in that tenant's pipeline, not the whole catalog.
     *
     * @param  Builder<Lead>  $query
     * @param  Collection<int, LeadStatus>  $tenantStatuses
     * @return array<int, array{status: string, count: int}>
     */
    public static function countsByStatus(Builder $query, Collection $tenantStatuses): array
    {
        $counts = $query
            ->selectRaw('status_id, count(*) as count')
            ->groupBy('status_id')
            ->pluck('count', 'status_id');

        return $tenantStatuses->map(fn (LeadStatus $status): array => [
            'status' => $status->id,
            'count' => (int) ($counts[$status->id] ?? 0),
        ])->all();
    }
}
