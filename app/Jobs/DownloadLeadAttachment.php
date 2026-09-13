<?php

namespace App\Jobs;

use App\Enums\LeadAttachmentStatus;
use App\Events\LeadUpdated;
use App\Models\LeadAttachment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

/**
 * Downloads a message attachment (image/video/audio/file) reported by a
 * webhook into an already-created `LeadAttachment` row (status=Downloading
 * at dispatch time — see LeadWebhookController::createPendingAttachment()).
 * Runs as a queued job rather than inline in the webhook request: Meta
 * disables a webhook subscription after enough slow/failed responses, and a
 * multi-megabyte download has no business happening inside that response
 * window.
 */
class DownloadLeadAttachment implements ShouldQueue
{
    use Queueable;

    /**
     * Never retried: the remote URL is short-lived (WhatsApp's resolved
     * media URL expires in 5 minutes; Messenger/Instagram CDN links are
     * similarly temporary), so a retry after backoff would just fail again
     * against an already-expired link.
     */
    public int $tries = 1;

    public int $timeout = 180;

    /**
     * @var array<string, int>
     */
    private const MAX_BYTES = [
        'facebook_messenger' => 25 * 1024 * 1024,
        'instagram' => 25 * 1024 * 1024,
        'whatsapp' => 100 * 1024 * 1024,
    ];

    /**
     * Hosts a downloaded attachment URL must resolve under. Webhook payloads
     * are attacker-reachable input (tenant_code is the only gate on who can
     * POST to these endpoints), so the attachment URL they carry is not
     * trusted enough to fetch unconditionally — without this, a forged
     * payload could make this job fetch an arbitrary internal URL (SSRF).
     *
     * @var array<int, string>
     */
    private const ALLOWED_HOST_SUFFIXES = ['fbcdn.net', 'fbsbx.com', 'cdninstagram.com'];

    public function __construct(
        public LeadAttachment $attachment,
        public string $senderId,
        public string $platform,
        public ?string $remoteUrl = null,
        public ?string $mediaId = null,
    ) {}

    public function handle(): void
    {
        $credentials = $this->resolveCredentials();

        if ($credentials === null) {
            $this->markFailed('Lead source credential is no longer configured.');

            return;
        }

        try {
            $download = $this->platform === 'whatsapp'
                ? $this->downloadWhatsappMedia($credentials['accessToken'], $credentials['whatsappPhoneNumberId'])
                : $this->downloadCdnAttachment($credentials['accessToken']);
        } catch (Throwable $e) {
            $this->markFailed($e->getMessage());

            return;
        }

        if ($download === null) {
            return;
        }

        $this->attachment->update([
            'path' => $download['path'],
            'mime' => $download['mime'],
            'original_media_url' => $download['source_url'],
            'status' => LeadAttachmentStatus::Downloaded,
            'failure_reason' => null,
        ]);

        event(new LeadUpdated($this->attachment->lead));
    }

    /**
     * @return array{path: string, mime: string, source_url: string}|null
     */
    private function downloadCdnAttachment(string $accessToken): ?array
    {
        if ($this->remoteUrl === null) {
            $this->markFailed('No remote URL was provided.');

            return null;
        }

        if (! $this->hostIsAllowed((string) parse_url($this->remoteUrl, PHP_URL_HOST))) {
            $this->markFailed("Disallowed host in attachment URL: {$this->remoteUrl}");

            return null;
        }

        $tmpPath = $this->tempPath();
        $response = Http::timeout(60)->withOptions(['sink' => $tmpPath])->get($this->remoteUrl);

        // These CDN links are normally pre-signed and fetchable as-is; if
        // that fails, fall back to appending the page/IG access token —
        // Meta's docs don't guarantee one behavior over the other.
        if (! $response->successful()) {
            $separator = str_contains($this->remoteUrl, '?') ? '&' : '?';
            $retryUrl = $this->remoteUrl.$separator.'access_token='.urlencode($accessToken);
            $response = Http::timeout(60)->withOptions(['sink' => $tmpPath])->get($retryUrl);
        }

        if (! $response->successful()) {
            @unlink($tmpPath);
            $this->markFailed("Download returned HTTP {$response->status()}.");

            return null;
        }

        return $this->finalize($tmpPath, $response->header('Content-Type'), $this->remoteUrl);
    }

    /**
     * @return array{path: string, mime: string, source_url: string}|null
     */
    private function downloadWhatsappMedia(string $accessToken, ?string $whatsappPhoneNumberId): ?array
    {
        if ($this->mediaId === null) {
            $this->markFailed('No WhatsApp media id was provided.');

            return null;
        }

        $lookup = Http::withToken($accessToken)
            ->timeout(15)
            ->get("https://graph.facebook.com/v21.0/{$this->mediaId}", array_filter([
                'phone_number_id' => $whatsappPhoneNumberId,
            ]));

        if (! $lookup->successful()) {
            $this->markFailed("WhatsApp media lookup HTTP {$lookup->status()}: ".substr($lookup->body(), 0, 500));

            return null;
        }

        $mediaUrl = $lookup->json('url');

        if (! is_string($mediaUrl) || $mediaUrl === '') {
            $this->markFailed('WhatsApp media lookup response had no url.');

            return null;
        }

        if (! $this->hostIsAllowed((string) parse_url($mediaUrl, PHP_URL_HOST))) {
            $this->markFailed("Disallowed host in WhatsApp media URL: {$mediaUrl}");

            return null;
        }

        $tmpPath = $this->tempPath();
        $response = Http::withToken($accessToken)->timeout(120)->withOptions(['sink' => $tmpPath])->get($mediaUrl);

        if (! $response->successful()) {
            @unlink($tmpPath);
            $this->markFailed("WhatsApp media download returned HTTP {$response->status()}.");

            return null;
        }

        return $this->finalize($tmpPath, $lookup->json('mime_type'), $mediaUrl);
    }

    private function hostIsAllowed(string $host): bool
    {
        $host = strtolower($host);

        foreach (self::ALLOWED_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Moves a downloaded temp file into its final, tenant/platform-scoped
     * path — but only once size and type have been validated. Rejects
     * (and deletes) anything over this platform's cap rather than trusting
     * a possibly-absent or spoofed Content-Length header.
     *
     * @return array{path: string, mime: string, source_url: string}|null
     */
    private function finalize(string $tmpAbsolutePath, ?string $mime, string $sourceUrl): ?array
    {
        $mime = $mime !== null ? trim(explode(';', $mime)[0]) : '';
        $mime = $mime !== '' ? $mime : (mime_content_type($tmpAbsolutePath) ?: 'application/octet-stream');

        $size = @filesize($tmpAbsolutePath) ?: 0;
        $maxBytes = self::MAX_BYTES[$this->platform] ?? self::MAX_BYTES['facebook_messenger'];

        if ($size === 0 || $size > $maxBytes) {
            @unlink($tmpAbsolutePath);
            $this->markFailed("Rejected: {$size} bytes (limit {$maxBytes}).");

            return null;
        }

        // A ULID rather than a timestamp: a sender can attach several files
        // within the same second (even within the same message), and a lead
        // can hold any number of attachments across its whole conversation —
        // a plain `{sender}_{timestamp}` name would collide and silently
        // overwrite an earlier download still referenced by a different
        // LeadAttachment row.
        $extension = MimeTypes::getDefault()->getExtensions($mime)[0] ?? 'bin';
        $relativePath = $this->targetDirectory().'/'.$this->safeSenderId().'_'.Str::ulid().'.'.$extension;

        rename($tmpAbsolutePath, Storage::disk('local')->path($relativePath));

        return ['path' => $relativePath, 'mime' => $mime, 'source_url' => $sourceUrl];
    }

    /**
     * Re-reads the access token (and, for WhatsApp, the phone_number_id)
     * fresh from the lead's LeadSource at execution time — never captured
     * at dispatch time and never passed through the job's constructor — so
     * neither value is ever serialized into the queued job payload or a
     * failed_jobs row. Mirrors WhatsAppSender::send()'s "read config fresh,
     * every time" convention.
     *
     * @return array{accessToken: string, whatsappPhoneNumberId: ?string}|null
     */
    private function resolveCredentials(): ?array
    {
        $config = $this->attachment->lead->source->config ?? [];
        $accessToken = $config['access_token'] ?? $config['page_access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            return null;
        }

        return [
            'accessToken' => $accessToken,
            'whatsappPhoneNumberId' => $this->platform === 'whatsapp' ? ($config['phone_number_id'] ?? null) : null,
        ];
    }

    private function markFailed(string $reason): void
    {
        Log::warning("Lead attachment download failed for attachment [{$this->attachment->id}]: {$reason}");

        $this->attachment->update([
            'status' => LeadAttachmentStatus::Failed,
            'failure_reason' => $reason,
        ]);
    }

    private function tempPath(): string
    {
        $dir = $this->targetDirectory();
        Storage::disk('local')->makeDirectory($dir);

        return Storage::disk('local')->path($dir.'/.tmp_'.Str::random(24));
    }

    private function targetDirectory(): string
    {
        $platformSlug = match ($this->platform) {
            'facebook_messenger' => 'messenger',
            default => $this->platform,
        };

        return "leads/{$this->attachment->lead->tenant->tenant_code}/{$platformSlug}";
    }

    private function safeSenderId(): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', $this->senderId) ?: 'unknown';
    }
}
