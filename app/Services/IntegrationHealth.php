<?php

namespace App\Services;

use App\Enums\FailedEmailMessageStatus;
use App\Enums\IntegrationProblem;
use App\Enums\LeadSourceType;
use App\Enums\TenantStatus;
use App\Enums\TokenCheckStatus;
use App\Models\FailedEmailMessage;
use App\Models\Lead;
use App\Models\LeadSource;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the rows of Admin → Monitoring → Integrations: every active lead
 * source of every active client (Manual and CSV import have no outside
 * connection and are left out), with its recent activity and problems.
 * Read-only, from data the app already records plus the daily Meta token
 * check (MetaTokenChecker).
 *
 * @phpstan-type IntegrationRow array{source: LeadSource, lastActivityAt: Carbon|null, leads7: int, leads30: int, problems: array<int, array{problem: IntegrationProblem, detail: string}>, info: string|null, severity: int}
 */
class IntegrationHealth
{
    public const QUIET_AFTER_DAYS = 7;

    public const TOKEN_EXPIRY_WARNING_DAYS = 7;

    public const RECENT_DAYS = 7;

    /**
     * @var list<LeadSourceType>
     */
    private const EXCLUDED_TYPES = [LeadSourceType::Manual, LeadSourceType::CsvImport];

    public function __construct(private readonly ImapConnectionHealthMonitor $imap) {}

    /**
     * Problems first (serious before warnings), then by client name.
     *
     * @return list<IntegrationRow>
     */
    public function rows(): array
    {
        $sources = LeadSource::query()
            ->where('is_active', true)
            ->whereNotIn('type', self::EXCLUDED_TYPES)
            ->whereHas('tenant', fn ($query) => $query->where('status', TenantStatus::Active))
            ->with(['tenant:id,name,tenant_code', 'tokenCheck'])
            ->get();

        $ids = $sources->modelKeys();
        $leads7 = $this->leadCountsSince($ids, now()->subDays(7));
        $leads30 = $this->leadCountsSince($ids, now()->subDays(30));
        $failedEmails = FailedEmailMessage::query()
            ->whereIn('lead_source_id', $ids)
            ->whereIn('status', [FailedEmailMessageStatus::Failed, FailedEmailMessageStatus::Exhausted])
            ->where('first_failed_at', '>=', now()->subDays(self::RECENT_DAYS))
            ->selectRaw('lead_source_id, count(*) as total')
            ->groupBy('lead_source_id')
            ->pluck('total', 'lead_source_id');

        $rows = [];

        foreach ($sources as $source) {
            $rows[] = $this->row($source, (int) ($leads7[$source->id] ?? 0), (int) ($leads30[$source->id] ?? 0), (int) ($failedEmails[$source->id] ?? 0));
        }

        usort($rows, fn (array $a, array $b): int => [$a['severity'], strtolower($a['source']->tenant->name)] <=> [$b['severity'], strtolower($b['source']->tenant->name)]);

        return $rows;
    }

    /**
     * @return IntegrationRow
     */
    public function row(LeadSource $source, int $leads7, int $leads30, int $failedEmails): array
    {
        $problems = [];
        $info = null;
        $lastActivityAt = $source->last_synced_at;

        // Quiet: nothing for 7+ days. A source younger than that just hasn't
        // had time yet.
        $quietSince = now()->subDays(self::QUIET_AFTER_DAYS);
        if ($lastActivityAt === null) {
            if ($source->created_at !== null && $source->created_at->lt($quietSince)) {
                $problems[] = ['problem' => IntegrationProblem::Quiet, 'detail' => (string) __('Nothing has ever arrived from this source.')];
            } else {
                $info = (string) __('No activity yet');
            }
        } elseif ($lastActivityAt->lt($quietSince)) {
            $problems[] = ['problem' => IntegrationProblem::Quiet, 'detail' => (string) __('Nothing has arrived for :time.', ['time' => $lastActivityAt->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE)])];
        }

        if ($source->last_signature_rejection_at !== null && $source->last_signature_rejection_at->gte(now()->subDays(self::RECENT_DAYS))) {
            $problems[] = ['problem' => IntegrationProblem::WebhookRejected, 'detail' => ($source->last_signature_rejection_reason ?? (string) __('A webhook signature was rejected.')).' ('.$source->last_signature_rejection_at->diffForHumans().')'];
        }

        if ($source->type === LeadSourceType::Email) {
            $connectFailures = $this->imap->consecutiveFailures($source);

            if ($connectFailures >= (int) config('ops.imap_failure_threshold', 5)) {
                $problems[] = ['problem' => IntegrationProblem::InboxCannotConnect, 'detail' => (string) __('Failed to connect :count times in a row.', ['count' => $connectFailures])];
            }

            if ($failedEmails > 0) {
                $problems[] = ['problem' => IntegrationProblem::EmailsFailed, 'detail' => (string) trans_choice(':count email failed to import in the last 7 days.|:count emails failed to import in the last 7 days.', $failedEmails)];
            }
        }

        if (MetaTokenChecker::isMetaSource($source)) {
            $problems = [...$problems, ...$this->tokenProblems($source)];

            if ($info === null && $source->tokenCheck === null && MetaTokenChecker::tokenOf($source) !== null) {
                $info = (string) __('Token not checked yet');
            }
        }

        if ($source->type === LeadSourceType::Whatsapp && $source->calling_enabled && $source->calling_meta_status !== null && $source->calling_meta_status !== 'ENABLED') {
            $problems[] = ['problem' => IntegrationProblem::CallingOffAtMeta, 'detail' => (string) __('Calling is on in OptiFin, but Meta reported it off when last checked :time.', ['time' => $source->calling_meta_checked_at?->diffForHumans() ?? ''])];
        }

        // 0 = has a serious problem, 1 = has warnings, 2 = fine.
        $severity = collect($problems)->contains(fn (array $p): bool => $p['problem']->isSerious()) ? 0 : ($problems === [] ? 2 : 1);

        return ['source' => $source, 'lastActivityAt' => $lastActivityAt, 'leads7' => $leads7, 'leads30' => $leads30, 'problems' => $problems, 'info' => $info, 'severity' => $severity];
    }

    /**
     * @return array<int, array{problem: IntegrationProblem, detail: string}>
     */
    private function tokenProblems(LeadSource $source): array
    {
        if (MetaTokenChecker::tokenOf($source) === null) {
            return [['problem' => IntegrationProblem::TokenMissing, 'detail' => (string) __('No access token is configured on this source.')]];
        }

        $check = $source->tokenCheck;

        if ($check === null) {
            return [];
        }

        if ($check->status === TokenCheckStatus::Invalid) {
            return [['problem' => IntegrationProblem::TokenInvalid, 'detail' => ($check->error ?? (string) __('Meta rejected the token.')).' ('.(string) __('checked :time', ['time' => $check->checked_at->diffForHumans()]).')']];
        }

        if ($check->status === TokenCheckStatus::Valid && $check->expires_at !== null && $check->expires_at->lt(now()->addDays(self::TOKEN_EXPIRY_WARNING_DAYS))) {
            return [['problem' => IntegrationProblem::TokenExpiresSoon, 'detail' => (string) __('The token expires :time.', ['time' => $check->expires_at->diffForHumans()])]];
        }

        return [];
    }

    /**
     * @param  array<int, string>  $sourceIds
     * @return Collection<string, int>
     */
    private function leadCountsSince(array $sourceIds, CarbonInterface $since): Collection
    {
        return Lead::query()
            ->whereIn('source_id', $sourceIds)
            ->whereNull('merged_into_lead_id')
            ->where('created_at', '>=', $since)
            ->selectRaw('source_id, count(*) as total')
            ->groupBy('source_id')
            ->pluck('total', 'source_id');
    }
}
