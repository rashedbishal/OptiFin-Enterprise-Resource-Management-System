<?php

namespace App\Support;

use App\Enums\LeadPriority;
use App\Models\Tenant;
use Illuminate\Validation\Rule;

/**
 * The single source of truth for Lead field-level validation rules —
 * extracted from App\Livewire\Tenant\Leads\Index::saveLead(), which used to
 * inline these directly. Every rule array here is byte-identical to what
 * saveLead() validated before this extraction (verified by the existing
 * manual-lead-creation test suite passing unmodified) — this class only
 * changes *where* the rules live, never what they validate.
 *
 * App\Imports\LeadCsvImport (Feature #7) calls these same methods for its
 * own per-row validation, so the two paths can never silently drift apart.
 * Rule *values* (regex patterns, exists-checks) are shared; custom error
 * *messages* are not — Livewire's field-level message bag and a CSV row's
 * "row N: <reason>" reporting are different UI concerns, not different
 * business rules, so each caller keeps its own message text.
 */
final class LeadValidationRules
{
    /**
     * @return array<int, string>
     */
    public static function name(): array
    {
        // Letters (incl. accented/Unicode), spaces, apostrophes, periods
        // and hyphens only - covers "Md. Rafiqul Islam", "O'Brien",
        // "Jean-Pierre" without allowing digits or stray symbols.
        return ['required', 'string', 'min:2', 'max:255', 'regex:/^[\p{L}\p{M}][\p{L}\p{M}\s\'.-]*$/u'];
    }

    /**
     * @return array<int, string>
     */
    public static function company(): array
    {
        return ['nullable', 'string', 'max:255', 'regex:/^[\p{L}\p{N}][\p{L}\p{N}\s.,&\'()-]*$/u'];
    }

    /**
     * @return array<int, string>
     */
    public static function website(): array
    {
        return ['nullable', 'url', 'max:255', 'regex:#^https?://#i'];
    }

    /**
     * @return array<int, string>
     */
    public static function industry(): array
    {
        return ['nullable', 'string', 'max:255', 'regex:#^[\p{L}][\p{L}\s&,/-]*$#u'];
    }

    /**
     * @return array<int, string>
     */
    public static function location(): array
    {
        return ['nullable', 'string', 'max:255', 'regex:/^[\p{L}][\p{L}\s,.-]*$/u'];
    }

    /**
     * @return array<int, string>
     */
    public static function serviceInterest(): array
    {
        return ['nullable', 'string', 'max:255', 'regex:#^[\p{L}\p{N}][\p{L}\p{N}\s&,./()-]*$#u'];
    }

    /**
     * The free-text `lead_source` column (e.g. "Referral", "Trade Show") -
     * distinct from the source()/source_id relation. Same regex shape as
     * serviceInterest() in the original inline rules.
     *
     * @return array<int, string>
     */
    public static function leadSourceText(): array
    {
        return ['nullable', 'string', 'max:255', 'regex:#^[\p{L}\p{N}][\p{L}\p{N}\s&,./()-]*$#u'];
    }

    /**
     * @return array<int, string>
     */
    public static function email(): array
    {
        return ['nullable', 'email', 'max:255'];
    }

    /**
     * @param  bool  $required  Manual creation requires a phone; CSV import
     *                          treats it as an optional column (Feature #7,
     *                          approved header list) - the only place these
     *                          two contexts intentionally differ, since
     *                          "required" is a per-context decision, not
     *                          part of the shared format rule itself.
     * @return array<int, string>
     */
    public static function phone(bool $required = true): array
    {
        // Digits, spaces and the punctuation real phone numbers use
        // (+880 1712-345678, +1 (555) 123-4567); the lookahead requires
        // at least 7 digits so "(--)  --" can't slip through.
        return [$required ? 'required' : 'nullable', 'regex:/^(?=(?:\D*\d){7,})[0-9+\-\s()]{7,20}$/'];
    }

    /**
     * @return array<int, string>
     */
    public static function message(): array
    {
        return ['nullable', 'string', 'max:2000'];
    }

    /**
     * @return array<int, mixed>
     */
    public static function statusId(Tenant $tenant): array
    {
        return ['required', Rule::exists('tenant_lead_statuses', 'lead_status_id')->where('tenant_id', $tenant->id)];
    }

    /**
     * @return array<int, mixed>
     */
    public static function priority(): array
    {
        return ['required', Rule::enum(LeadPriority::class)];
    }

    /**
     * Shared by estimated_budget and deal_value - plain decimal amounts
     * only (no scientific notation, no thousands separators).
     *
     * @return array<int, string>
     */
    public static function amount(): array
    {
        return ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'regex:/^\d{1,12}(\.\d{1,2})?$/'];
    }

    /**
     * Shared by estimated_budget_currency_id and deal_value_currency_id.
     *
     * @return array<int, mixed>
     */
    public static function currencyId(Tenant $tenant): array
    {
        return ['nullable', 'string', Rule::exists('tenant_currencies', 'currency_id')->where('tenant_id', $tenant->id)];
    }

    /**
     * @return array<int, string>
     */
    public static function winProbability(): array
    {
        return ['nullable', 'integer', 'min:0', 'max:100'];
    }

    /**
     * @return array<int, string>
     */
    public static function nextFollowUpAt(): array
    {
        return ['nullable', 'date', 'after_or_equal:today'];
    }

    /**
     * @return array<int, string>
     */
    public static function meetingDate(): array
    {
        return ['nullable', 'date_format:Y-m-d\TH:i', 'after_or_equal:today'];
    }
}
