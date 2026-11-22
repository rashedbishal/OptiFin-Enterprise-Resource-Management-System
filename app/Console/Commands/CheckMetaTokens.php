<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Models\LeadSource;
use App\Services\MetaTokenChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily, read-only check of every active Meta lead source's access token —
 * results feed Admin → Monitoring → Integrations. One source failing never
 * stops the others.
 */
#[Signature('integrations:check-meta-tokens')]
#[Description('Check (read-only) whether each active Meta lead source\'s access token is still valid and when it expires')]
class CheckMetaTokens extends Command
{
    public function handle(MetaTokenChecker $checker): int
    {
        $checked = 0;

        LeadSource::query()
            ->where('is_active', true)
            ->whereIn('type', MetaTokenChecker::META_TYPES)
            ->whereHas('tenant', fn ($query) => $query->where('status', TenantStatus::Active))
            ->chunkById(100, function ($sources) use ($checker, &$checked): void {
                foreach ($sources as $source) {
                    try {
                        $checker->check($source);
                        $checked++;
                    } catch (Throwable $exception) {
                        // Class only: a message could echo request details.
                        Log::warning('integrations:check-meta-tokens: a token check failed.', ['lead_source_id' => $source->id, 'exception' => $exception::class]);
                    }
                }
            });

        $this->info("Checked {$checked} Meta token(s).");

        return self::SUCCESS;
    }
}
