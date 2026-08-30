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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tenant_id', 'type', 'name', 'config', 'is_active', 'last_synced_at'])]
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
        ];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'source_id');
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
            LeadSourceType::FacebookMessenger => 'api.webhook.facebook',
            LeadSourceType::Whatsapp => 'api.webhook.whatsapp',
            LeadSourceType::Instagram => 'api.webhook.instagram',
            LeadSourceType::WebsiteForm => 'api.webhook.website-form',
            LeadSourceType::Email => null,
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
