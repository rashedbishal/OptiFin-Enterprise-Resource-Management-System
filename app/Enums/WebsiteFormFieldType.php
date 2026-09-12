<?php

namespace App\Enums;

/**
 * Feature #13 — distinguishes a WebsiteFormField that references a built-in
 * Lead column (email/phone/message — `name` is always implicit, see
 * App\Models\WebsiteFormField's docblock) from one that references an
 * existing App\Models\LeadCustomFieldDefinition. Deliberately a plain
 * string-backed enum, not a DB enum, matching LeadScoringRule::field's own
 * reasoning.
 *
 * Feature #14 adds a third case, `Consent` — neither a fixed Lead column nor
 * a Custom Field reference, so it needs its own first-class field type
 * rather than being modeled as either (see App\Enums\ConsentType and
 * App\Models\WebsiteFormField's consent_type/is_required/policy_version/
 * policy_url columns, meaningful only when field_type = Consent).
 */
enum WebsiteFormFieldType: string
{
    case Fixed = 'fixed';
    case Custom = 'custom';
    case Consent = 'consent';
}
