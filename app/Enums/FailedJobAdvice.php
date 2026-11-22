<?php

namespace App\Enums;

/**
 * What the Failed jobs page suggests a Platform Admin does with one failed
 * job — see App\Services\FailedJobService::advise() for how it's chosen.
 * Worded for non-developers.
 */
enum FailedJobAdvice: string
{
    case Retry = 'retry';
    case Delete = 'delete';
    case Developer = 'developer';
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::Retry => __('Retry'),
            self::Delete => __('Delete'),
            self::Developer => __('Ask a developer'),
            self::Review => __('Not sure'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Retry => 'green',
            self::Delete => 'zinc',
            self::Developer => 'red',
            self::Review => 'yellow',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Retry => 'arrow-path',
            self::Delete => 'trash',
            self::Developer => 'code-bracket',
            self::Review => 'question-mark-circle',
        };
    }
}
