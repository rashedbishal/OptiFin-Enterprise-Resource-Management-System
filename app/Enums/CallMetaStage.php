<?php

namespace App\Enums;

/**
 * The last Meta Calling API step that SUCCEEDED for a claimed call (null =
 * none yet). Lets a retry by the same claimant resend only the missing step:
 * Meta requires the accept SDP to match the pre_accept SDP, so a completed
 * pre_accept must never be redone with a different answer.
 */
enum CallMetaStage: string
{
    case PreAccepted = 'pre_accepted';
    case Accepted = 'accepted';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
