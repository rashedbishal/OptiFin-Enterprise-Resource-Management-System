<?php

namespace App\Services;

use App\Enums\MessageChannel;
use App\Enums\MessageType;
use App\Models\Conversation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

/**
 * Stores one outbound image/PDF on the private `local` disk and returns the
 * Message columns that point at it. The stored name is always a fresh ULID
 * plus an extension derived from the file's own sniffed content type —
 * never anything the browser supplied — and the directory is deliberately
 * `outbound/`, not `leads/`, since leads:cleanup-attachments deletes any
 * `leads/` file that has no LeadAttachment row (see
 * CleanupOrphanedLeadAttachments, which sweeps `outbound/` against
 * messages.attachment_path instead).
 */
class OutboundMediaStorage
{
    public const string DIRECTORY = 'outbound';

    private const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
    ];

    /**
     * @return array{message_type: MessageType, attachment_path: string, attachment_mime: string, attachment_name: string, attachment_size: int}
     */
    public function store(TemporaryUploadedFile|UploadedFile $file, Conversation $conversation, MessageType $type): array
    {
        $mime = self::sniffMime($file);
        $extension = self::EXTENSIONS[$mime] ?? 'bin';
        $directory = self::DIRECTORY.'/'.$conversation->lead->tenant->tenant_code.'/'.$conversation->channel->value;
        $name = Str::ulid().'.'.$extension;

        $path = Storage::disk('local')->putFileAs($directory, $file, $name);

        if ($path === false) {
            throw new RuntimeException('The outbound file could not be stored.');
        }

        return [
            'message_type' => $type,
            'attachment_path' => $path,
            'attachment_mime' => $mime,
            'attachment_name' => $this->displayName($file->getClientOriginalName(), $extension),
            'attachment_size' => (int) $file->getSize(),
        ];
    }

    /**
     * The name shown to staff and sent to WhatsApp as a document's filename:
     * base name only, control characters and path separators stripped,
     * capped, and always ending in the real extension.
     */
    private function displayName(string $clientName, string $extension): string
    {
        $base = pathinfo(str_replace('\\', '/', $clientName), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', $base));
        $base = Str::limit($base !== '' ? $base : 'file', 100, '');

        return $base.'.'.$extension;
    }

    /**
     * @return array<int, string>
     */
    public static function mimeTypesFor(MessageChannel $channel, MessageType $type): array
    {
        return $type === MessageType::Image ? $channel->imageMimeTypes() : $channel->documentMimeTypes();
    }

    public static function humanSize(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? round($bytes / (1024 * 1024), 1).' MB'
            : max(1, (int) round($bytes / 1024)).' KB';
    }

    /**
     * The file's real content type, read from its bytes with finfo — never
     * the browser-supplied type, the extension, or Flysystem's guess (which
     * falls back to the extension for anything that looks like plain text,
     * so a text file named `.pdf` would otherwise pass as a PDF).
     */
    public static function sniffMime(TemporaryUploadedFile|UploadedFile $file): string
    {
        $path = $file->getRealPath();
        $mime = $path === false ? false : (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($mime) ? $mime : 'application/octet-stream';
    }
}
