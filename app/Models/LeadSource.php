<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\LeadSourceType;
use Database\Factories\LeadSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property LeadSourceType $type
 * @property string $name
 * @property array<string, mixed>|null $config
 * @property bool $is_active
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $last_signature_rejection_at
 * @property string|null $last_signature_rejection_reason
 * @property Carbon|null $token_expires_at
 * @property bool $calling_enabled
 * @property string|null $calling_meta_status
 * @property Carbon|null $calling_meta_checked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 */
#[Fillable([
    'tenant_id', 'type', 'name', 'config', 'is_active', 'last_synced_at',
    'last_signature_rejection_at', 'last_signature_rejection_reason', 'token_expires_at',
    'calling_enabled', 'calling_meta_status', 'calling_meta_checked_at',
])]
class LeadSource extends Model
{
    /** @use HasFactory<LeadSourceFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LeadSourceType::class,
            'config' => 'encrypted:array',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_signature_rejection_at' => 'datetime',
            'token_expires_at' => 'datetime',
            'calling_enabled' => 'boolean',
            'calling_meta_checked_at' => 'datetime',
        ];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'source_id');
    }

    /**
     * Feature #12 — this source's recorded monthly spend history, tenant
     * self-service (see App\Models\LeadSourceCost's docblock). Never
     * populated for Manual/CsvImport sources by UI convention (not a DB
     * constraint) — those aren't real paid channels.
     *
     * @return HasMany<LeadSourceCost, $this>
     */
    public function costs(): HasMany
    {
        return $this->hasMany(LeadSourceCost::class);
    }

    /**
     * The public webhook URL for this source, or null for source types that
     * aren't webhook-based (e.g. email).
     *
     * Built from `config('app.url')` rather than `route(..., absolute: true)`
     * because the admin viewing this page is very likely on a different
     * host (e.g. `localhost`) than the public URL Meta/webhooks must call
     * (e.g. an ngrok tunnel) — `route(absolute: true)` reflects whatever
     * host the *current* request came in on, which is the wrong value here.
     */
    public function webhookUrl(): ?string
    {
        $routeName = match ($this->type) {
            // Lead Ads shares Messenger's webhook URL: Meta delivers both
            // `messaging` events and `leadgen` change events to the same
            // Page-level webhook subscription, not separate URLs.
            LeadSourceType::FacebookMessenger, LeadSourceType::FacebookLeadAds => 'api.webhook.facebook',
            LeadSourceType::Whatsapp => 'api.webhook.whatsapp',
            LeadSourceType::Instagram => 'api.webhook.instagram',
            LeadSourceType::WebsiteForm => 'api.webhook.website-form',
            // No route yet — LinkedIn's webhook endpoint doesn't exist
            // until Phase 4 registers it. Until then this source is
            // poll-only in practice, so it falls back to the same "Polled
            // every :min min" display Email already uses. Wiring this to a
            // route name before that route exists would throw a
            // RouteNotFoundException the moment this page renders a
            // LinkedIn source row.
            LeadSourceType::LinkedInLeadGen => null,
            LeadSourceType::Email => null,
            // Never receives webhooks - leads are typed in directly.
            LeadSourceType::Manual => null,
            // Never receives webhooks - leads arrive via an uploaded CSV
            // file, processed by App\Imports\LeadCsvImport.
            LeadSourceType::CsvImport => null,
        };

        if (! $routeName) {
            return null;
        }

        return rtrim((string) config('app.url'), '/').route($routeName, $this->tenant->tenant_code, absolute: false);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
