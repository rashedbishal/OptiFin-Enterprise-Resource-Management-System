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
 * lowercased/trimmed, phone has non-digits stripped. Normalization happens
 * exactly once, in normalizeEmail()/normalizePhone() below — never
 * reimplemented in SQL. The candidate query (see detectFor()) compares
 * against leads.email_normalized/phone_normalized, two application-computed
 * columns App\Observers\LeadObserver::saving() keeps in sync with
 * email/phone on every write using these exact same two methods, so a
 * targeted indexed lookup and this class's own PHP normalization can never
 * disagree — deliberately NOT database-generated columns or expression
 * indexes, since SQLite (this app's test driver) has no regex-replace
 * equivalent for normalizePhone()'s digit-stripping, and a database's own
 * LOWER() can disagree with normalizeEmail()'s multibyte-aware Str::lower()
 * on Unicode edge cases.
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
     * M4 fix: candidates are found via a targeted, indexed lookup against
     * leads.email_normalized/phone_normalized (backed by
     * (tenant_id, email_normalized)/(tenant_id, phone_normalized) indexes)
     * instead of fetching every one of the tenant's leads and comparing in
     * PHP — this previously scaled linearly with tenant size on every call
     * (and every lead detail page view triggers one), quadratically across
     * DetectDuplicateLeads's sweep.
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
                ->where(function (Builder $query) use ($normalizedEmail, $normalizedPhone): void {
                    if ($normalizedEmail !== null) {
                        $query->orWhere('email_normalized', $normalizedEmail);
                    }

                    if ($normalizedPhone !== null) {
                        $query->orWhere('phone_normalized', $normalizedPhone);
                    }
                })
                ->get(['id', 'tenant_id', 'email_normalized', 'phone_normalized']);

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
     * Compares against the candidate's already-normalized columns directly
     * — not self::normalizeEmail($candidate->email) — since
     * App\Observers\LeadObserver::saving() guarantees email_normalized/
     * phone_normalized already equal what re-normalizing the raw columns
     * would produce, for every lead in the table.
     *
     * @return array<int, string>
     */
    private function matchedFields(?string $normalizedEmail, ?string $normalizedPhone, Lead $candidate): array
    {
        $matched = [];

        if ($normalizedEmail !== null && $candidate->email_normalized === $normalizedEmail) {
            $matched[] = 'email';
        }

        if ($normalizedPhone !== null && $candidate->phone_normalized === $normalizedPhone) {
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
