<?php

namespace App\Services;

use App\Enums\LeadDuplicateLinkStatus;
use App\Models\Lead;
use App\Models\LeadDuplicateLink;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Cross-channel duplicate detection: flags two same-tenant leads as possible
 * duplicates when their normalized email or phone match, regardless of which
 * capture channel each one came through. Deliberately NOT hooked into any of
 * the three lead-creation paths (CreateManualLead, LeadWebhookController,
 * ProcessEmailLeadSource) — this only ever reads/upserts LeadDuplicateLink
 * rows after the fact, so none of the existing per-channel dedupe logic
 * (Messenger/Instagram/WhatsApp sender dedupe, Facebook Lead Ads leadgen_id
 * dedupe, email append-by-address) is touched.
 *
 * Matching is exact-after-normalization, not fuzzy: email is
 * lowercased/trimmed, phone has non-digits stripped. Comparison happens in
 * PHP (see matches()) rather than in SQL, so it works identically regardless
 * of the app's DB driver — acceptable at typical per-tenant lead volumes; if
 * a tenant's lead table ever gets large enough for this to matter, a future
 * revision could push normalization into generated/indexed columns instead.
 */
class LeadDuplicateDetectionService
{
    /**
     * Finds every other same-tenant lead that matches $lead on normalized
     * email or phone, records a LeadDuplicateLink for each pair not already
     * known (never re-creates one that's already pending/dismissed/merged —
     * see LeadDuplicateLink::pairKeys()), and returns every pending link now
     * involving $lead.
     *
     * @return Collection<int, LeadDuplicateLink>
     */
    public function detectFor(Lead $lead): Collection
    {
        if ($lead->merged_into_lead_id !== null) {
            return collect();
        }

        $normalizedEmail = self::normalizeEmail($lead->email);
        $normalizedPhone = self::normalizePhone($lead->phone);

        if ($normalizedEmail !== null || $normalizedPhone !== null) {
            $candidates = Lead::query()
                ->where('tenant_id', $lead->tenant_id)
                ->where('id', '!=', $lead->id)
                ->whereNull('merged_into_lead_id')
                ->where(function (Builder $query): void {
                    $query->whereNotNull('email')->orWhereNotNull('phone');
                })
                ->get(['id', 'tenant_id', 'email', 'phone']);

            foreach ($candidates as $candidate) {
                $matchedFields = $this->matchedFields($normalizedEmail, $normalizedPhone, $candidate);

                if ($matchedFields !== []) {
                    $this->recordPendingLink($lead, $candidate, $matchedFields);
                }
            }
        }

        return $this->pendingLinksFor($lead);
    }

    /**
     * Every still-open (pending) possible-duplicate pairing involving
     * $lead, with the other lead in the pair eager-loaded — read-only, does
     * not detect/create anything.
     *
     * @return Collection<int, LeadDuplicateLink>
     */
    public function pendingLinksFor(Lead $lead): Collection
    {
        return LeadDuplicateLink::query()
            ->where('tenant_id', $lead->tenant_id)
            ->where(function (Builder $query) use ($lead): void {
                $query->where('lead_id', $lead->id)->orWhere('duplicate_lead_id', $lead->id);
            })
            ->where('status', LeadDuplicateLinkStatus::Pending)
            ->with(['lead', 'duplicateLead'])
            ->get();
    }

    /**
     * @return array<int, string>
     */
    private function matchedFields(?string $normalizedEmail, ?string $normalizedPhone, Lead $candidate): array
    {
        $matched = [];

        if ($normalizedEmail !== null && self::normalizeEmail($candidate->email) === $normalizedEmail) {
            $matched[] = 'email';
        }

        if ($normalizedPhone !== null && self::normalizePhone($candidate->phone) === $normalizedPhone) {
            $matched[] = 'phone';
        }

        return $matched;
    }

    /**
     * @param  array<int, string>  $matchedFields
     */
    private function recordPendingLink(Lead $a, Lead $b, array $matchedFields): void
    {
        [$leadId, $duplicateLeadId] = LeadDuplicateLink::pairKeys($a, $b);

        LeadDuplicateLink::query()->firstOrCreate(
            [
                'lead_id' => $leadId,
                'duplicate_lead_id' => $duplicateLeadId,
            ],
            [
                'tenant_id' => $a->tenant_id,
                'status' => LeadDuplicateLinkStatus::Pending,
                'matched_fields' => $matchedFields,
                'detected_at' => now(),
            ],
        );
    }

    public static function normalizeEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email === '' ? null : Str::lower($email);
    }

    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return $digits === null || $digits === '' ? null : $digits;
    }
}
