<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Services\LeadReportingService::firstResponseSummary() when
 * the given date range exceeds MAX_RANGE_DAYS or has $from after $to — a
 * defense-in-depth guard so the query is never reachable with an
 * out-of-policy range, even by a caller other than App\Livewire\Tenant\
 * LeadReports\Index (which already enforces the same policy itself before
 * ever calling this method).
 */
class LeadReportingRangeException extends RuntimeException {}
