<?php

namespace App\Actions\Tenant;

use App\Enums\LeadImportRowStatus;
use App\Enums\LeadPriority;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\LeadImportRow;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\LeadTag;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\LeadAssignmentService;
use App\Services\LeadDuplicateDetectionService;
use App\Services\LeadScoringService;
use App\Support\LeadValidationRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Processes exactly one CSV data row for Feature #7 (called by
 * App\Imports\LeadCsvImport::model() once per row, always in strict
 * ascending row-number order — see that class for why). Writes exactly one
 * App\Models\LeadImportRow recording the outcome and atomically increments
 * the parent LeadImport's counters; never throws — every failure mode
 * (validation miss, unresolved reference, duplicate) is recorded as a row
 * result, not an exception, so one bad row can never abort the batch.
 *
 * Deliberately separate from App\Actions\Tenant\CreateManualLead (which is
 * shaped around the Livewire manual-entry form and its own defaulting
 * rules) rather than retrofitted with import-specific concerns.
 */
class CreateLeadFromImport
{
    public function __construct(
        private readonly LeadAssignmentService $assignmentService,
        private readonly LeadScoringService $scoringService,
    ) {}

    /**
     * @param  array<string, mixed>  $row  Raw CSV row, keyed by the exact
     *                                     approved header names.
     */
    public function handle(
        LeadImport $import,
        Tenant $tenant,
        LeadSource $source,
        TenantUser $importedBy,
        int $rowNumber,
        array $row,
    ): void {
        // Idempotency guard: a chunk job retried after a partial failure
        // must never reprocess a row it already committed. The unique
        // (lead_import_id, row_number) index backs this up at the DB
        // level too, but checking first avoids a needless failed insert.
        if (LeadImportRow::where('lead_import_id', $import->id)->where('row_number', $rowNumber)->exists()) {
            return;
        }

        try {
            $this->processRow($import, $tenant, $source, $importedBy, $rowNumber, $row);
        } catch (Throwable $e) {
            // Any unexpected failure (not just a validation miss) is
            // recorded as a failed row rather than allowed to bubble up
            // and crash the whole chunk job — see the class docblock.
            $this->recordRow($import, $rowNumber, LeadImportRowStatus::Failed, $row, [$e->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function processRow(LeadImport $import, Tenant $tenant, LeadSource $source, TenantUser $importedBy, int $rowNumber, array $row): void
    {
        $errors = $this->validateFields($row);

        if ($errors !== []) {
            $this->recordRow($import, $rowNumber, LeadImportRowStatus::Failed, $row, $errors);

            return;
        }

        $name = trim((string) ($row['name'] ?? ''));
        $email = $this->blankToNull($row['email'] ?? null);
        $phone = $this->blankToNull($row['phone'] ?? null);

        $normalizedEmail = LeadDuplicateDetectionService::normalizeEmail($email);
        $normalizedPhone = LeadDuplicateDetectionService::normalizePhone($phone);

        // In-file check first, deliberately: once an earlier row in this
        // same import has already won and committed its own real Lead row,
        // the existing-Lead check below would also match it — checking
        // in-file first gives a more precise "duplicate of an earlier row
        // in this CSV" attribution (duplicate_of_lead_id left null) instead
        // of the generic "matches an existing lead" one, and is what
        // actually makes normalized_email/normalized_phone load-bearing
        // rather than redundant with the existing-Lead query.
        $duplicateError = $this->checkInFileDuplicate($import, $normalizedEmail, $normalizedPhone)
            ?? $this->checkExistingLeadDuplicate($tenant, $normalizedEmail, $normalizedPhone);

        if ($duplicateError !== null) {
            [$message, $duplicateOfLeadId] = $duplicateError;
            $this->recordRow($import, $rowNumber, LeadImportRowStatus::Duplicate, $row, [$message], $normalizedEmail, $normalizedPhone, $duplicateOfLeadId);

            return;
        }

        // TOCTOU fix: $status is now resolved inside the transaction below,
        // immediately after taking a shared (FOR SHARE) coordination lock on
        // the tenant row — see
        // Platform\Clients\LeadSources::toggleLeadStatus()'s own docblock
        // for the full readers/writer protocol. The other 5 resolutions
        // don't need the lock, but stay alongside $status inside the same
        // transaction so every resolution failure is still reported
        // together in one combined Failed row, exactly as before — only
        // moved from before the transaction to its first statements.
        DB::transaction(function () use ($import, $tenant, $source, $importedBy, $rowNumber, $row, $name, $email, $phone, $normalizedEmail, $normalizedPhone): void {
            // $status is resolved from this freshly-locked $lockedTenant,
            // never the outer $tenant parameter — $tenant may be reused
            // across every row in the same chunk (see App\Imports\LeadCsvImport)
            // and could carry a stale cached leadStatuses relation from an
            // earlier row, which would defeat the lock's purpose here.
            $lockedTenant = Tenant::whereKey($tenant->id)->sharedLock()->firstOrFail();

            $status = $this->resolveStatus($lockedTenant, $row['status'] ?? null);
            $priority = $this->resolvePriority($row['priority'] ?? null);
            $assignee = $this->resolveAssignee($tenant, $row['assignee_email'] ?? null);
            $tags = $this->resolveTags($tenant, $row['tags'] ?? null);
            $budgetCurrencyId = $this->resolveCurrencyId($tenant, $row['budget'] ?? null, $row['budget_currency'] ?? null);
            $dealValueCurrencyId = $this->resolveCurrencyId($tenant, $row['deal_value'] ?? null, $row['deal_value_currency'] ?? null);
            $resolutionErrors = [];

            if ($status === false) {
                $resolutionErrors[] = __('Unknown status: :value', ['value' => (string) ($row['status'] ?? '')]);
            }

            if ($priority === false) {
                $resolutionErrors[] = __('Unknown priority: :value', ['value' => (string) ($row['priority'] ?? '')]);
            }

            if ($assignee === false) {
                $resolutionErrors[] = __('Unknown assignee_email: :value', ['value' => (string) ($row['assignee_email'] ?? '')]);
            }

            if ($tags === false) {
                $resolutionErrors[] = __('One or more tags do not exist for this tenant.');
            }

            if ($budgetCurrencyId === false) {
                $resolutionErrors[] = __('Unknown budget_currency: :value', ['value' => (string) ($row['budget_currency'] ?? '')]);
            }

            if ($dealValueCurrencyId === false) {
                $resolutionErrors[] = __('Unknown deal_value_currency: :value', ['value' => (string) ($row['deal_value_currency'] ?? '')]);
            }

            if ($resolutionErrors !== []) {
                $this->recordRow($import, $rowNumber, LeadImportRowStatus::Failed, $row, $resolutionErrors, $normalizedEmail, $normalizedPhone);

                return;
            }

            /** @var LeadStatus $status */
            /** @var LeadPriority $priority */
            /** @var array<int, LeadTag> $tags */
            /** @var string|null $budgetCurrencyId */
            /** @var string|null $dealValueCurrencyId */
            $lead = Lead::create([
                'tenant_id' => $tenant->id,
                'source_id' => $source->id,
                'name' => $name,
                'company' => $this->blankToNull($row['company'] ?? null),
                'industry' => $this->blankToNull($row['industry'] ?? null),
                'location' => $this->blankToNull($row['location'] ?? null),
                'service_interest' => $this->blankToNull($row['service_interest'] ?? null),
                'email' => $email,
                'phone' => $phone,
                'status_id' => $status->id,
                'priority' => $priority,
                'estimated_budget' => $this->blankToNull($row['budget'] ?? null),
                'estimated_budget_currency_id' => $budgetCurrencyId,
                'deal_value' => $this->blankToNull($row['deal_value'] ?? null),
                'deal_value_currency_id' => $dealValueCurrencyId,
            ]);

            // M6 — $lead is freshly created, in this same unbroken
            // execution, from exactly these two already-resolved
            // instances: setting them directly lets computeFor() below
            // skip its own source/status reload for this call only (via
            // its opt-in $trustLoadedRelations parameter), with no risk
            // of the stale-relation scenario that parameter's default
            // (still used by every other caller) exists to guard against.
            $lead->setRelation('source', $source);
            $lead->setRelation('status', $status);

            // assignee is null|false|TenantUser at this point; false was
            // already turned into a row failure above, so only null|TenantUser
            // reach here.
            if ($assignee instanceof TenantUser) {
                $this->assignmentService->assign($lead, $assignee, $importedBy);
            }

            foreach ($tags as $tag) {
                $lead->tags()->syncWithoutDetaching([$tag->id => ['tagged_by' => $importedBy->id]]);
            }

            // CSV imports never fire LeadReceived (would fan out
            // auto-assignment/new-lead notifications per row at import
            // scale) — this mirrors CreateManualLead's own workaround for
            // the same reason: it's the only path to an initial score.
            $this->scoringService->computeFor($lead, 'lead_received', trustLoadedRelations: true);

            $this->recordRow($import, $rowNumber, LeadImportRowStatus::Imported, $row, null, $normalizedEmail, $normalizedPhone, null, $lead->id);
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function validateFields(array $row): array
    {
        $validator = Validator::make([
            'name' => $row['name'] ?? null,
            'email' => $this->blankToNull($row['email'] ?? null),
            'phone' => $this->blankToNull($row['phone'] ?? null),
            'company' => $this->blankToNull($row['company'] ?? null),
            'industry' => $this->blankToNull($row['industry'] ?? null),
            'location' => $this->blankToNull($row['location'] ?? null),
            'service_interest' => $this->blankToNull($row['service_interest'] ?? null),
            'budget' => $this->blankToNull($row['budget'] ?? null),
            'deal_value' => $this->blankToNull($row['deal_value'] ?? null),
        ], [
            'name' => LeadValidationRules::name(),
            'email' => LeadValidationRules::email(),
            'phone' => LeadValidationRules::phone(required: false),
            'company' => LeadValidationRules::company(),
            'industry' => LeadValidationRules::industry(),
            'location' => LeadValidationRules::location(),
            'service_interest' => LeadValidationRules::serviceInterest(),
            'budget' => LeadValidationRules::amount(),
            'deal_value' => LeadValidationRules::amount(),
        ]);

        if ($validator->fails()) {
            return $validator->errors()->all();
        }

        return [];
    }

    /**
     * @return array{0: string, 1: ?string}|null
     */
    private function checkExistingLeadDuplicate(Tenant $tenant, ?string $normalizedEmail, ?string $normalizedPhone): ?array
    {
        if ($normalizedEmail === null && $normalizedPhone === null) {
            return null;
        }

        $emailMatch = $normalizedEmail !== null
            ? Lead::where('tenant_id', $tenant->id)->whereNull('merged_into_lead_id')->where('email_normalized', $normalizedEmail)->first(['id'])
            : null;

        $phoneMatch = $normalizedPhone !== null
            ? Lead::where('tenant_id', $tenant->id)->whereNull('merged_into_lead_id')->where('phone_normalized', $normalizedPhone)->first(['id'])
            : null;

        if ($emailMatch !== null && $phoneMatch !== null && $emailMatch->id !== $phoneMatch->id) {
            return [__('Email matches an existing lead and phone matches a different existing lead — rejected as a duplicate conflict.'), null];
        }

        $match = $emailMatch ?? $phoneMatch;

        if ($match !== null) {
            return [__('Matches an existing lead (email or phone already on file).'), $match->id];
        }

        return null;
    }

    /**
     * @return array{0: string, 1: null}|null
     */
    private function checkInFileDuplicate(LeadImport $import, ?string $normalizedEmail, ?string $normalizedPhone): ?array
    {
        if ($normalizedEmail === null && $normalizedPhone === null) {
            return null;
        }

        // Safe without a lock: rows are processed in strict ascending
        // row-number order with no possible concurrency (see
        // App\Imports\LeadCsvImport's docblock) — by the time this row
        // runs, every earlier row that could have already won the same
        // email/phone is guaranteed to already be committed here.
        $exists = LeadImportRow::where('lead_import_id', $import->id)
            ->where('status', LeadImportRowStatus::Imported)
            ->where(function ($query) use ($normalizedEmail, $normalizedPhone): void {
                if ($normalizedEmail !== null) {
                    $query->orWhere('normalized_email', $normalizedEmail);
                }

                if ($normalizedPhone !== null) {
                    $query->orWhere('normalized_phone', $normalizedPhone);
                }
            })
            ->exists();

        if ($exists) {
            return [__('Duplicate of an earlier row in this same CSV file.'), null];
        }

        return null;
    }

    /**
     * Resolves against this tenant's configured pipeline, matching by
     * label case-insensitively. Blank defaults to the tenant's initial
     * status, mirroring how the manual-entry form pre-fills it. Returns
     * `false` (never null) when the CSV supplied a value that doesn't
     * resolve, so the caller can distinguish "not supplied" from "invalid".
     */
    private function resolveStatus(Tenant $tenant, mixed $value): LeadStatus|false
    {
        $value = $this->blankToNull($value);

        if ($value === null) {
            return $tenant->initialLeadStatus() ?? false;
        }

        $status = $tenant->leadStatuses->first(fn (LeadStatus $status): bool => mb_strtolower($status->name) === mb_strtolower($value));

        return $status ?? false;
    }

    private function resolvePriority(mixed $value): LeadPriority|false
    {
        $value = $this->blankToNull($value);

        if ($value === null) {
            return LeadPriority::Medium;
        }

        return LeadPriority::tryFrom(mb_strtolower($value)) ?? false;
    }

    /**
     * Only ever resolved against this tenant's own users — never trusted
     * across tenant boundaries. `null` means "not supplied, leave
     * unassigned"; `false` means "supplied but unresolvable".
     */
    private function resolveAssignee(Tenant $tenant, mixed $value): TenantUser|null|false
    {
        $value = $this->blankToNull($value);

        if ($value === null) {
            return null;
        }

        $assignee = TenantUser::where('tenant_id', $tenant->id)->where('email', $value)->first();

        return $assignee ?? false;
    }

    /**
     * Comma-separated existing tag names — every name must resolve, or the
     * whole row fails (never a partial attach). Never auto-creates a tag.
     *
     * @return array<int, LeadTag>|false
     */
    private function resolveTags(Tenant $tenant, mixed $value): array|false
    {
        $value = $this->blankToNull($value);

        if ($value === null) {
            return [];
        }

        $names = array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $n): bool => $n !== ''));

        if ($names === []) {
            return [];
        }

        $tags = LeadTag::where('tenant_id', $tenant->id)
            ->get()
            ->filter(fn (LeadTag $tag): bool => in_array(mb_strtolower($tag->name), array_map(mb_strtolower(...), $names), true))
            ->values();

        return $tags->count() === count(array_unique(array_map(mb_strtolower(...), $names))) ? $tags->all() : false;
    }

    /**
     * A currency without an amount means nothing (same rule
     * Show::updateDeal() and Index::saveLead() already enforce) — an
     * amount is stored with whatever currency was supplied, or null if
     * none was. Unlike Index::saveLead() (whose dropdown submits an
     * internal currency id), a CSV column supplies a currency CODE (e.g.
     * "USD") — resolved against this tenant's own enabled currencies
     * (tenant_currencies), matched by code. Returns `false` (never null)
     * when a currency code was actually supplied but doesn't resolve, so
     * the caller can fail the row rather than silently dropping it.
     */
    private function resolveCurrencyId(Tenant $tenant, mixed $amount, mixed $currencyCode): string|null|false
    {
        $amount = $this->blankToNull($amount);
        $currencyCode = $this->blankToNull($currencyCode);

        if ($amount === null || $currencyCode === null) {
            return null;
        }

        $currency = $tenant->currencies()->where('code', $currencyCode)->first();

        return $currency === null ? false : $currency->id;
    }

    /**
     * @param  array<int, string>|null  $errors
     * @param  array<string, mixed>  $row
     */
    private function recordRow(
        LeadImport $import,
        int $rowNumber,
        LeadImportRowStatus $status,
        array $row,
        ?array $errors = null,
        ?string $normalizedEmail = null,
        ?string $normalizedPhone = null,
        ?string $duplicateOfLeadId = null,
        ?string $leadId = null,
    ): void {
        DB::transaction(function () use ($import, $rowNumber, $status, $row, $errors, $normalizedEmail, $normalizedPhone, $duplicateOfLeadId, $leadId): void {
            LeadImportRow::create([
                'lead_import_id' => $import->id,
                'row_number' => $rowNumber,
                'status' => $status,
                'lead_id' => $leadId,
                'normalized_email' => $normalizedEmail,
                'normalized_phone' => $normalizedPhone,
                'raw_data' => $row,
                'errors' => $errors,
                'duplicate_of_lead_id' => $duplicateOfLeadId,
            ]);

            $column = match ($status) {
                LeadImportRowStatus::Imported => 'imported_count',
                LeadImportRowStatus::Duplicate => 'duplicate_count',
                LeadImportRowStatus::Failed => 'failed_count',
            };

            LeadImport::whereKey($import->id)->increment($column);
        });
    }

    private function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
