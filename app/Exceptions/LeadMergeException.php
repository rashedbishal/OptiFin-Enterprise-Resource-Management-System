<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Services\LeadMergeService when a merge is attempted across
 * tenants, on a lead against itself, or on a lead that's already been merged
 * away — guard failures that must abort before any write happens, not just
 * be prevented by the UI.
 */
class LeadMergeException extends RuntimeException {}
