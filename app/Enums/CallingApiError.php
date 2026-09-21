<?php

namespace App\Enums;

/**
 * WhatsApp Calling API — the deterministic, app-owned category of a failed
 * Meta call. Callers branch on the category and show `userMessage()`; Meta's own
 * `error.message` is never surfaced (its wording is not a contract and may echo
 * account details). The raw Meta code, HTTP status and fbtrace_id travel
 * separately on the result for diagnostics.
 *
 * Meta's documented calling codes are mapped explicitly; the general Graph
 * codes (190 token, 10 / 200–299 permission, 100 parameter) follow Graph API
 * conventions rather than the calling docs, so they are an assumption to
 * confirm against a real test number. Anything unmapped falls back on the HTTP
 * status, then Unknown.
 */
enum CallingApiError: string
{
    case Validation = 'validation';
    case Authentication = 'authentication';
    case Permission = 'permission';
    case CallingNotEnabled = 'calling_not_enabled';
    case TemporarilyDisabled = 'temporarily_disabled';
    case NotEligible = 'not_eligible';
    case SipNumber = 'sip_number';
    case Payment = 'payment';
    case Limit = 'limit';
    case Transient = 'transient';
    case Network = 'network';
    case InvalidResponse = 'invalid_response';
    case Unknown = 'unknown';

    /**
     * Classify a failed response. `$metaCode` is Meta's `error.code` when the
     * body carried one.
     */
    public static function classify(?int $metaCode, ?int $httpStatus): self
    {
        if ($metaCode !== null) {
            $byCode = match (true) {
                $metaCode === 138000 => self::CallingNotEnabled,
                $metaCode === 138014 => self::TemporarilyDisabled,
                in_array($metaCode, [138013, 138015, 138018], true) => self::NotEligible,
                $metaCode === 131055 => self::SipNumber,
                $metaCode === 131044 => self::Payment,
                in_array($metaCode, [138002, 138005, 138009, 138012, 613], true) => self::Limit,
                in_array($metaCode, [138004, 138007], true), $metaCode >= 138019 && $metaCode <= 138023 => self::Transient,
                $metaCode === 190 => self::Authentication,
                $metaCode === 10, $metaCode >= 200 && $metaCode <= 299 => self::Permission,
                $metaCode === 100 => self::Validation,
                default => null,
            };

            if ($byCode !== null) {
                return $byCode;
            }
        }

        return match (true) {
            $httpStatus === 401 => self::Authentication,
            $httpStatus === 403 => self::Permission,
            $httpStatus === 429 => self::Limit,
            $httpStatus !== null && $httpStatus >= 500 => self::Transient,
            $httpStatus !== null && $httpStatus >= 400 => self::Validation,
            default => self::Unknown,
        };
    }

    /**
     * A safe, fixed, admin-facing sentence — never derived from Meta's text.
     */
    public function userMessage(): string
    {
        return match ($this) {
            self::Validation => __('WhatsApp rejected the request. Check the source\'s phone number ID and try again.'),
            self::Authentication => __('WhatsApp rejected the access token. Update the source\'s access token and try again.'),
            self::Permission => __('The access token does not have permission to manage calling for this number.'),
            self::CallingNotEnabled => __('Calling is not enabled for this WhatsApp number.'),
            self::TemporarilyDisabled => __('WhatsApp has temporarily disabled calling for this number.'),
            self::NotEligible => __('This WhatsApp number does not meet WhatsApp\'s requirements for calling.'),
            self::SipNumber => __('This number uses SIP calling, which cannot be managed through this API.'),
            self::Payment => __('WhatsApp reports that no valid payment method is attached to this account.'),
            self::Limit => __('WhatsApp is rate limiting this request. Try again in a few minutes.'),
            self::Transient => __('WhatsApp had a temporary problem. Try again in a few minutes.'),
            self::Network => __('Could not reach WhatsApp. Try again in a few minutes.'),
            self::InvalidResponse => __('WhatsApp returned an unexpected response.'),
            self::Unknown => __('WhatsApp returned an error.'),
        };
    }
}
