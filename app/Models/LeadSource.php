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
 * @property string|null $singleton_type
 * @property string $name
 * @property array<string, mixed>|null $config
 * @property string|null $webhook_token_hash
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
    'calling_enabled', 'calling_meta_status', 'calling_meta_checked_at', 'webhook_token_hash',
])]
class LeadSource extends Model
{
    /** @use HasFactory<LeadSourceFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    /**
     * The only LeadSourceType values confirmed, by direct evidence, as an
     * enforceable singleton-per-tenant: LeadSourceType's own case comments
     * ("the single per-tenant source... lazily created on first use"), and
     * — critically — neither is ever independently creatable through any
     * other path (both are excluded from Platform\Clients\LeadSources::
     * typeOptions(), so the Platform Admin UI can never create a second
     * one), meaning CreateManualLead/LeadImports\Index's own firstOrCreate()
     * really is the ONLY writer for each.
     *
     * WebsiteForm is deliberately NOT included despite WebsiteForm::
     * leadSource()'s own docblock describing it as "the tenant's ONE shared
     * ... LeadSource": that describes the Website Form BUILDER's own
     * lazy-creation intent specifically, not an app-wide invariant — unlike
     * Manual/CsvImport, WebsiteForm IS independently creatable through the
     * Platform Admin panel (LeadSources::save() imposes no restriction for
     * it, unlike Manual/CsvImport which typeOptions() excludes entirely),
     * and enforcing "at most one" at the database level here broke over a
     * hundred existing tests that already create multiple WebsiteForm-type
     * sources per tenant for unrelated scenarios — concrete, empirical
     * evidence the wider system does not actually treat this as an absolute
     * rule, whatever the one relationship's own docblock aspires to. Forcing
     * it through would be a real, invasive behavior change, not a race fix.
     *
     * Every other type (FacebookMessenger, FacebookLeadAds, Whatsapp,
     * Instagram, Email, LinkedInLeadGen) is confirmed to legitimately allow
     * multiple sources per tenant the same way WebsiteForm does — no
     * restriction in LeadSources::save(), and an existing, already-passing
     * test (LeadSourceCostReportingTest.php) exercises 5 FacebookLeadAds
     * sources for one tenant as normal, expected behavior.
     *
     * @var array<int, LeadSourceType>
     */
    public const array SINGLETON_TYPES = [
        LeadSourceType::Manual,
        LeadSourceType::CsvImport,
    ];

    /**
     * F1 remediation (forensic audit Batch A) — the LeadSourceTypes whose
     * inbound webhook can be authenticated by an opaque per-source token
     * (webhook_token_hash) rather than routing on tenant_code alone. The
     * same four types VerifyMetaWebhookSignature's own app_secret opt-in
     * already covers — Email/LinkedInLeadGen/WebsiteForm/Manual/CsvImport
     * either have no webhook at all or already have their own, separate
     * credential (WebsiteForm.token_hash, the legacy website-form api_key).
     *
     * @var array<int, LeadSourceType>
     */
    public const array META_WEBHOOK_TYPES = [
        LeadSourceType::FacebookMessenger,
        LeadSourceType::FacebookLeadAds,
        LeadSourceType::Whatsapp,
        LeadSourceType::Instagram,
    ];

    protected static function booted(): void
    {
        // Derives singleton_type from type on every creation path
        // automatically, rather than scattering the same three-type check
        // across CreateManualLead/LeadImports\Index/WebsiteForms\Index (and
        // any future creator) — see the unique(tenant_id, singleton_type)
        // index this column backs (migration
        // 2026_09_28_070000_add_singleton_type_unique_to_lead_sources_table)
        // for the full reasoning. type is never mutated after creation
        // anywhere in this codebase, so this only needs to run once, here.
        static::creating(function (LeadSource $source): void {
            $source->singleton_type = in_array($source->type, self::SINGLETON_TYPES, true)
                ? $source->type->value
                : null;
        });
    }

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
     *
     * F1 remediation (forensic audit Batch A) — for a META_WEBHOOK_TYPES
     * source that already has a webhook_token_hash, this now returns the
     * opaque, token-bearing URL (api.webhook.{platform}.token) instead of
     * the legacy tenant_code-only one: the token-bearing route is what the
     * admin should actually configure at Meta going forward. A source of
     * one of those same four types that has NOT yet been given a token
     * (every source that existed before this migration, until it's next
     * created/edited — see LeadSources::ensureWebhookToken()) still
     * correctly returns the legacy URL here, since that is genuinely the
     * only URL that currently works for it — this method must never claim
     * a security property a given row doesn't actually have.
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

        if (! in_array($this->type, self::META_WEBHOOK_TYPES, true) || $this->webhook_token_hash === null) {
            return rtrim((string) config('app.url'), '/').route($routeName, $this->tenant->tenant_code, absolute: false);
        }

        // The plaintext token is never persisted (see
        // LeadSources::ensureWebhookToken()'s own docblock), so it can never
        // be recovered here — this row already has a token, but this method
        // can only render the URL's own SHAPE, not a working copy of it,
        // exactly like WebsiteForms\Index::submissionUrl() already does for
        // the same reason: a safe, unencoded sentinel is passed to route()
        // so it appears verbatim (never URL-encoded) in the output, then
        // swapped for the literal placeholder text the admin sees. The real
        // URL is only ever shown once, at generation time, via
        // LeadSources::$revealedToken.
        $sentinel = 'WEBHOOKTOKENPLACEHOLDER';

        return str_replace(
            $sentinel,
            '{token}',
            rtrim((string) config('app.url'), '/').route($routeName.'.token', [$this->tenant->tenant_code, $sentinel], absolute: false),
        );
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
