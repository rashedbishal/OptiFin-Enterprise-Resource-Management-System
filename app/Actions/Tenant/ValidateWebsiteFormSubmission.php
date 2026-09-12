<?php

namespace App\Actions\Tenant;

use App\Enums\ConsentType;
use App\Enums\WebsiteFormFieldType;
use App\Enums\WebsiteFormFixedField;
use App\Models\WebsiteForm;
use App\Support\LeadValidationRules;
use Closure;
use Illuminate\Support\Facades\Validator;

/**
 * Feature #13 — validates a public Website Form submission strictly against
 * that specific form's OWN server-stored field configuration, never
 * trusting anything about which fields exist, whether they're required, or
 * their type from the request itself. Deliberately does NOT create the
 * Lead — that stays App\Http\Controllers\Api\LeadWebhookController::
 * createLead()'s job, the single place every channel's lead creation
 * already happens, so this class is purely "resolve + validate + shape."
 *
 * Every submitted key not covered by this form's own configuration is
 * silently discarded before validation ever runs — a builder form is a
 * fully-specified schema, unlike the legacy webhook's "keep every extra
 * field" behavior, which this class never touches or replicates.
 */
class ValidateWebsiteFormSubmission
{
    /**
     * A fixed, well-known hidden-field convention — real visitors never
     * fill it in (hidden via CSS on the tenant's own page), so a non-empty
     * value here means a bot. Deliberately not per-form/randomized: a
     * "basic honeypot" per the approved scope, not a second moving part to
     * configure.
     */
    public const string HONEYPOT_FIELD = '_gotcha';

    /**
     * @param  array<string, mixed>  $payload
     * @return array{honeypot: bool, attributes: array<string, mixed>, customFieldValues: array<string, mixed>, consentGrants: array<int, array{type: ConsentType, policyVersion: ?string, policyUrl: ?string}>}
     */
    public function handle(WebsiteForm $form, array $payload): array
    {
        if (trim((string) ($payload[self::HONEYPOT_FIELD] ?? '')) !== '') {
            return ['honeypot' => true, 'attributes' => [], 'customFieldValues' => [], 'consentGrants' => []];
        }

        $fields = $form->fields()->with('customFieldDefinition')->get();

        $rules = ['name' => LeadValidationRules::name()];
        $input = ['name' => $payload['name'] ?? null];

        foreach ($fields->where('field_type', WebsiteFormFieldType::Fixed) as $field) {
            $fixedField = WebsiteFormFixedField::tryFrom((string) $field->fixed_field);

            if ($fixedField === null) {
                continue;
            }

            $rules[$fixedField->value] = match ($fixedField) {
                WebsiteFormFixedField::Email => LeadValidationRules::email(),
                WebsiteFormFixedField::Phone => LeadValidationRules::phone(required: false),
                WebsiteFormFixedField::Message => LeadValidationRules::message(),
            };
            $input[$fixedField->value] = $payload[$fixedField->value] ?? null;
        }

        // Only definitions that are STILL active are validated/accepted —
        // a deleted-or-deactivated field referenced by this form is
        // silently skipped, never rejecting the rest of the submission
        // over one stale field (deletion itself is blocked while
        // referenced — see LeadCustomFieldDefinition::
        // isReferencedByWebsiteForm() — so "deleted" is only ever a
        // defensive case here, not a normal one).
        $definitionsByKey = [];

        foreach ($fields->where('field_type', WebsiteFormFieldType::Custom) as $field) {
            $definition = $field->customFieldDefinition;

            if ($definition === null || ! $definition->is_active) {
                continue;
            }

            $rules[$definition->key] = $definition->validationRules();
            $input[$definition->key] = $payload[$definition->key] ?? null;
            $definitionsByKey[$definition->key] = $definition;
        }

        // Feature #14 — a consent field is neither a fixed Lead column nor a
        // Custom Field reference, so it's validated with its own checkbox-
        // shaped semantics rather than LeadValidationRules/
        // LeadCustomFieldType: an HTML checkbox that's UNCHECKED is omitted
        // from the POST body entirely (never sent as an empty string), so
        // "absent" and "present but false" both mean the visitor did not
        // affirmatively check the box — treated identically. Only an
        // explicit, valid true value ever produces a grant.
        $consentFieldsByKey = [];

        foreach ($fields->where('field_type', WebsiteFormFieldType::Consent) as $field) {
            $consentType = $field->consent_type;

            if ($consentType === null) {
                continue;
            }

            $key = $consentType->value;
            $consentFieldsByKey[$key] = $field;

            $rules[$key] = [function (string $attribute, mixed $value, Closure $fail) use ($field, $consentType): void {
                if ($value === null || $value === '') {
                    if ($field->is_required) {
                        $fail(__(':attribute must be accepted.', ['attribute' => $consentType->label()]));
                    }

                    return;
                }

                if ($this->normalizeConsentBoolean($value) === null) {
                    $fail(__(':attribute has an invalid value.', ['attribute' => $consentType->label()]));

                    return;
                }

                if ($field->is_required && $this->normalizeConsentBoolean($value) === false) {
                    $fail(__(':attribute must be accepted.', ['attribute' => $consentType->label()]));
                }
            }];
            $input[$key] = $payload[$key] ?? null;
        }

        $validated = Validator::make($input, $rules)->validate();

        $customFieldValues = [];

        foreach ($definitionsByKey as $key => $definition) {
            $customFieldValues[$definition->id] = $validated[$key] ?? null;
        }

        $consentGrants = [];

        foreach ($consentFieldsByKey as $key => $field) {
            if ($this->normalizeConsentBoolean($validated[$key] ?? null) === true) {
                $consentGrants[] = [
                    'type' => $field->consent_type,
                    'policyVersion' => $field->policy_version,
                    'policyUrl' => $field->policy_url,
                ];
            }
        }

        return [
            'honeypot' => false,
            'attributes' => [
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'message' => $validated['message'] ?? null,
            ],
            'customFieldValues' => $customFieldValues,
            'consentGrants' => $consentGrants,
        ];
    }

    /**
     * A submitted consent value's boolean meaning — accepts the same
     * lenient true/false-ish string set App\Livewire\Tenant\LeadScoring\
     * Index::normalizeValueForStorage() already accepts for a Boolean-kind
     * scoring field, plus HTML's own default unlabeled-checkbox value
     * ("on"), since a tenant's own HTML checkbox may not set an explicit
     * value attribute. Returns null for anything else, which the caller
     * treats as an invalid, rejected value.
     */
    private function normalizeConsentBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = is_string($value) ? strtolower(trim($value)) : $value;

        return match (true) {
            in_array($value, ['1', 'true', 'on', 'yes'], true) => true,
            in_array($value, ['0', 'false', 'off', 'no'], true) => false,
            default => null,
        };
    }
}
