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
use Illuminate\Support\Facades\DB;

/**
 * @property string $id
 * @property string $identifier
 * @property string|null $merged_into_lead_id
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
 * @property string|null $estimated_budget_currency_id
 * @property string|null $deal_value
 * @property string|null $deal_value_currency_id
 * @property int|null $win_probability
 * @property int|null $lead_score
 * @property Carbon|null $lead_score_computed_at
 * @property Carbon|null $next_follow_up_at
 * @property Carbon|null $meeting_date
 * @property string|null $meeting_time
 * @property Carbon|null $assigned_at
 * @property Carbon|null $first_contacted_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $update_seen_at
 * @property-read Tenant $tenant
 * @property-read Currency|null $estimatedBudgetCurrency
 * @property-read Currency|null $dealValueCurrency
 */
// lead_score/lead_score_computed_at (like merged_into_lead_id/identifier)
// are deliberately left out here — they're only ever written by
// App\Services\LeadScoringService's raw query builder update, never via a
// create()/update() payload.
#[Fillable([
    'tenant_id', 'source_id', 'assigned_to', 'name', 'company', 'website', 'industry', 'location',
    'service_interest', 'lead_source', 'email', 'phone', 'message', 'html_body', 'source_data', 'status_id', 'priority',
    'notes', 'estimated_budget', 'estimated_budget_currency_id', 'deal_value', 'deal_value_currency_id',
    'win_probability', 'next_follow_up_at', 'meeting_date', 'meeting_time', 'assigned_at', 'first_contacted_at', 'closed_at',
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
            'lead_score' => 'integer',
            'lead_score_computed_at' => 'datetime',
            'next_follow_up_at' => 'date',
            'meeting_date' => 'date',
            'assigned_at' => 'datetime',
            'first_contacted_at' => 'datetime',
            'closed_at' => 'datetime',
            'update_seen_at' => 'datetime',
        ];
    }

    /**
     * `identifier` is deliberately left out of #[Fillable] above — it must
     * only ever come from here, never from a create() payload (see the
     * Fillable-attribute trap noted in CLAUDE.md).
     */
    protected static function booted(): void
    {
        static::creating(function (Lead $lead): void {
            if (! $lead->identifier) {
                $lead->identifier = self::nextIdentifier($lead->tenant_id);
            }
        });
    }

    /**
     * Reserves the next {tenant_prefix}-LEAD-{n} identifier for a tenant
     * under a row lock on that tenant, so a burst of concurrent lead
     * creations (e.g. several webhook deliveries at once) can never hand
     * out the same number twice. Numbers only ever go up — deleting a lead
     * never reclaims or reuses its number.
     */
    private static function nextIdentifier(string $tenantId): string
    {
        return DB::transaction(function () use ($tenantId): string {
            $tenant = Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $number = $tenant->next_lead_number;
            $tenant->increment('next_lead_number');

            return "{$tenant->lead_id_prefix}-LEAD-{$number}";
        });
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
     * Whether this lead came from a Facebook Lead Ads (Instant Forms)
     * submission — gates the Kanban card detail panel's dynamic field list
     * (see leadAdFieldValues()) and the Lead Source form field's hidden
     * state, since these leads carry no fixed contact-form shape.
     */
    public function isFacebookLeadAd(): bool
    {
        return $this->source->type === LeadSourceType::FacebookLeadAds;
    }

    /**
     * Whether this lead came from a Website Form submission — gates the
     * lead detail page's "Additional Details" field list (see
     * websiteFormFieldValues()).
     */
    public function isWebsiteForm(): bool
    {
        return $this->source->type === LeadSourceType::WebsiteForm;
    }

    /**
     * Every field a Website Form submission carried beyond the four with
     * their own dedicated columns (name/email/phone/message) — a client's
     * contact form can have any number of fields, under any names,
     * unknowable in advance, so `LeadWebhookController::websiteForm()`
     * stores literally everything else the request posted straight into
     * `source_data` (`$request->except(['name', 'email', 'phone',
     * 'message'])`), not a wrapped sub-key the way Facebook Lead Ads' raw
     * `field_data` payload is (compare leadAdFieldValues()) — so for a
     * Website Form lead, `source_data` in its entirety already IS this
     * field list, needing no extraction step.
     *
     * A field's value is normalized to a plain string for display: a
     * multi-value field (e.g. a checkbox group posted as `interests[]`)
     * arrives as an array and is comma-joined; anything else non-scalar
     * (a client posting nested JSON) falls back to a compact JSON dump
     * rather than letting Blade choke on an uncastable array-to-string.
     *
     * @return array<string, string>
     */
    public function websiteFormFieldValues(): array
    {
        $data = $this->source_data ?? [];

        if (! is_array($data)) {
            return [];
        }

        $values = [];

        foreach ($data as $field => $value) {
            if (! is_string($field) || $field === '' || $value === null || $value === '') {
                continue;
            }

            $values[$field] = $this->stringifyWebsiteFormFieldValue($value);
        }

        return $values;
    }

    private function stringifyWebsiteFormFieldValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return collect($value)
                ->map(fn (mixed $item): string => is_scalar($item) ? (string) $item : json_encode($item))
                ->implode(', ');
        }

        return (string) json_encode($value);
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
     * The lead this one was merged into, if any — see
     * App\Services\LeadMergeService::merge(). `merged_into_lead_id` is
     * deliberately left out of #[Fillable] above, same trap/pattern as
     * `identifier`: it must only ever be set by LeadMergeService's own raw
     * update, never from a create()/update() payload.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_lead_id');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function estimatedBudgetCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'estimated_budget_currency_id');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function dealValueCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'deal_value_currency_id');
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

    /**
     * Whether the Kanban card's meeting-date shimmer should be showing right
     * now. $enabled/$daysBefore come from the tenant's alert settings and
     * are passed in rather than read from $this->tenant, so a board render
     * doesn't do a per-card tenant lookup (every lead on one board shares
     * the same tenant). No upper bound once the window opens — it stays
     * "active" indefinitely until the meeting is cancelled or marked done,
     * which is the only thing that's supposed to stop it.
     */
    public function meetingAlertActive(bool $enabled, int $daysBefore): bool
    {
        if (! $enabled || $this->meeting_date === null) {
            return false;
        }

        return today()->gte($this->meeting_date->copy()->subDays($daysBefore));
    }

    /**
     * "2:30 PM" if a time was recorded alongside the meeting date, else
     * null — record-only metadata, never read by meetingAlertActive()'s
     * date-based comparison above.
     */
    public function meetingTimeFormatted(): ?string
    {
        return $this->meeting_time !== null ? Carbon::parse($this->meeting_time)->format('g:i A') : null;
    }

    /**
     * Same rules as meetingAlertActive(), for the Next Follow-up date.
     */
    public function followUpAlertActive(bool $enabled, int $daysBefore): bool
    {
        if (! $enabled || $this->next_follow_up_at === null) {
            return false;
        }

        return today()->gte($this->next_follow_up_at->copy()->subDays($daysBefore));
    }

    /**
     * Whether this lead has an update nobody has opened it to see yet —
     * drives the Leads list's amber "recently updated" bold/highlight,
     * which (unlike the separate, still time-windowed "just created"
     * highlight) stays until markUpdateSeen() runs, not on any timer, and
     * survives a full page reload since it's read from the DB rather than
     * a Livewire component property. Excludes the first 2 seconds after
     * creation so the initial `updated_at` a fresh row gets doesn't also
     * register as an "update" on top of the creation highlight.
     */
    public function hasUnseenUpdate(): bool
    {
        if ($this->updated_at === null || $this->created_at === null || $this->updated_at->lte($this->created_at->addSeconds(2))) {
            return false;
        }

        return $this->update_seen_at === null || $this->updated_at->gt($this->update_seen_at);
    }

    /**
     * Marks any pending update as seen — call when a viewer opens the lead
     * (Show::mount(), Index::viewLead()). A plain query builder update
     * rather than save(), deliberately: it must not touch `updated_at`
     * itself (that would make the lead look freshly updated again to every
     * other viewer) or fire model events/broadcasts for what is, from
     * their perspective, a no-op.
     */
    public function markUpdateSeen(): void
    {
        DB::table('leads')->where('id', $this->id)->update(['update_seen_at' => now()]);

        $this->update_seen_at = now();
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
     * The two halves of LeadDuplicateLink's canonical, order-independent
     * pair storage (see LeadDuplicateLink::pairKeys()) — a possible
     * duplicate pairing involving this lead lives under whichever of these
     * two relations matches how the pair happened to be ordered, never
     * both. Show::activity() combines both to render "merged with :lead"
     * regardless of which side this lead landed on.
     *
     * @return HasMany<LeadDuplicateLink, $this>
     */
    public function duplicateLinksAsLead(): HasMany
    {
        return $this->hasMany(LeadDuplicateLink::class, 'lead_id');
    }

    /**
     * @return HasMany<LeadDuplicateLink, $this>
     */
    public function duplicateLinksAsDuplicate(): HasMany
    {
        return $this->hasMany(LeadDuplicateLink::class, 'duplicate_lead_id');
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
