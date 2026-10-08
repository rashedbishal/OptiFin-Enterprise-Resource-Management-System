<?php

namespace App\Support;

/**
 * The outcome of one Platform Admin CreateMessengerMessageTemplate attempt,
 * ready for the UI: a toast variant and a safe message. Mirrors
 * CallingActionResult's shape, minus the Calling-specific restrictions/
 * warnings fields this action has no equivalent of.
 */
final class MessengerTemplateActionResult
{
    /**
     * @param  'success'|'danger'  $variant
     */
    public function __construct(
        public readonly string $variant,
        public readonly string $message,
    ) {}
}
