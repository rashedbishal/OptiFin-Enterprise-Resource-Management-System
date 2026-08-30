<?php

namespace App\Enums;

enum LeadAttachmentStatus: string
{
    case Downloading = 'downloading';
    case Downloaded = 'downloaded';
    case Failed = 'failed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
