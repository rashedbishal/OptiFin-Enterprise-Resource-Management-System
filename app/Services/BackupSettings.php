<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\PlatformUser;
use App\Support\SettingsAudit;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\FileAttributes;
use League\Flysystem\StorageAttributes;
use Spatie\Backup\Tasks\Backup\BackupJob;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Backblaze B2 storage + archive password for the backups, managed by the
 * Mother Admin at Admin → Settings → Backups and stored encrypted in
 * PlatformSetting (the secrets are never shown again). applyToConfig() turns
 * them into the `backups` filesystem disk and the backup archive password
 * that spatie/laravel-backup (config/backup.php) uses. Nothing saved = the
 * scheduled backup commands don't run.
 *
 * @phpstan-type BackupStorage array{key_id: string, application_key: string, bucket: string, endpoint: string, password: string}
 * @phpstan-type BackupFile array{path: string, date: CarbonInterface, size: int}
 * @phpstan-type BackupStatus array{count: int, newestAt: CarbonInterface|null, totalBytes: int, checkedAt: CarbonInterface}
 */
class BackupSettings
{
    public const SETTING_KEY = 'backup.storage';

    public const DISK = 'backups';

    /**
     * How long the backup status (a B2 file listing) is reused. B2's free
     * tier allows about 2,500 listing calls a day; the Health check runs
     * every minute, so it must not list every time.
     */
    private const STATUS_CACHE_MINUTES = 30;

    // v2: v1 cached Carbon objects, which the cache can't rebuild.
    private const STATUS_CACHE_KEY = 'backups:status:v2';

    /**
     * @var BackupStorage|false|null false = looked up, nothing saved
     */
    private array|false|null $storage = null;

    public function isConfigured(): bool
    {
        return $this->storage() !== null;
    }

    /**
     * What the settings page shows: no secrets.
     *
     * @return array{key_id: string, bucket: string, endpoint: string, updated_at: CarbonInterface|null, updated_by: string|null}|null
     */
    public function summary(): ?array
    {
        $setting = PlatformSetting::query()->with('updatedBy:id,name')->where('key', self::SETTING_KEY)->first();
        $value = $setting?->value;

        if (! is_array($value) || ! $this->isValid($value)) {
            return null;
        }

        return [
            'key_id' => $value['key_id'],
            'bucket' => $value['bucket'],
            'endpoint' => $value['endpoint'],
            'updated_at' => $setting->updated_at,
            'updated_by' => $setting->updatedBy?->name,
        ];
    }

    /**
     * Empty application key / password keep the saved ones.
     *
     * @param  array{key_id: string, application_key: ?string, bucket: string, endpoint: string, password: ?string}  $values
     */
    public function store(array $values, PlatformUser $by): void
    {
        $current = $this->storage();
        $stored = [
            'key_id' => $values['key_id'],
            'application_key' => filled($values['application_key']) ? $values['application_key'] : ($current['application_key'] ?? ''),
            'bucket' => $values['bucket'],
            'endpoint' => $values['endpoint'],
            'password' => filled($values['password']) ? $values['password'] : ($current['password'] ?? ''),
        ];

        PlatformSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => $stored, 'updated_by' => $by->id],
        );

        $this->storage = null;
        $this->forgetStatus();

        SettingsAudit::record('Backups', 'saved', SettingsAudit::changes($current, $stored, ['application_key', 'password']), $by);
    }

    public function remove(): void
    {
        PlatformSetting::query()->where('key', self::SETTING_KEY)->delete();

        $this->storage = null;
        $this->forgetStatus();

        SettingsAudit::record('Backups', 'removed');
    }

    /**
     * Fills in the `backups` disk and the archive password. A no-op when
     * nothing is saved, so the package then has no usable destination.
     */
    public function applyToConfig(): void
    {
        $storage = $this->storage();

        if ($storage === null) {
            return;
        }

        config([
            'filesystems.disks.'.self::DISK => $this->diskConfig($storage),
            'backup.backup.password' => $storage['password'],
        ]);

        Storage::forgetDisk(self::DISK);
    }

    /**
     * B2's S3-compatible API.
     *
     * @param  BackupStorage  $storage
     * @return array<string, mixed>
     */
    public function diskConfig(array $storage): array
    {
        return [
            'driver' => 's3',
            'key' => $storage['key_id'],
            'secret' => $storage['application_key'],
            'region' => self::regionFromEndpoint($storage['endpoint']),
            'bucket' => $storage['bucket'],
            'endpoint' => 'https://'.$storage['endpoint'],
            'use_path_style_endpoint' => false,
            'visibility' => 'private',
            'throw' => true,
            // B2 doesn't accept the AWS SDK's newer default checksum headers.
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ];
    }

    /**
     * "s3.us-west-004.backblazeb2.com" → "us-west-004".
     */
    public static function regionFromEndpoint(string $endpoint): string
    {
        return preg_match('/^s3\.([a-z0-9-]+)\.backblazeb2\.com$/', $endpoint, $matches) === 1 ? $matches[1] : 'us-east-1';
    }

    /**
     * Writes, reads back and deletes a tiny file. Throws with B2's own error
     * message when something is wrong (bad key, wrong bucket or endpoint).
     */
    public function testConnection(): void
    {
        $this->applyToConfig();

        $path = config('backup.backup.name').'/connection-test-'.Str::random(8).'.txt';
        $disk = Storage::disk(self::DISK);

        $disk->put($path, 'OptiFin backup connection test');

        try {
            if ($disk->get($path) !== 'OptiFin backup connection test') {
                throw new \RuntimeException(__('The test file could not be read back.'));
            }
        } finally {
            $disk->delete($path);
        }
    }

    /**
     * Every backup on B2, newest first. One B2 listing call: the listing
     * already carries each file's size and date, so this never asks B2 about
     * files one by one (which would eat into B2's free daily allowance).
     * Errors propagate so the page can say B2 is unreachable.
     *
     * @return Collection<int, BackupFile>
     */
    public function backups(): Collection
    {
        if (! $this->isConfigured()) {
            return collect();
        }

        $this->applyToConfig();

        $listing = Storage::disk(self::DISK)->getDriver()->listContents((string) config('backup.backup.name'), true);

        return collect(iterator_to_array($listing, false))
            ->filter(fn (StorageAttributes $item): bool => $item instanceof FileAttributes && str_ends_with($item->path(), '.zip'))
            ->map(fn (FileAttributes $file): array => [
                'path' => $file->path(),
                'date' => $this->backupDate($file),
                'size' => (int) $file->fileSize(),
            ])
            ->sortByDesc(fn (array $backup): int => $backup['date']->getTimestamp())
            ->values();
    }

    /**
     * @return BackupFile|null
     */
    public function find(string $path): ?array
    {
        return $this->backups()->first(fn (array $backup): bool => $backup['path'] === $path);
    }

    public function download(string $path): ?StreamedResponse
    {
        $backup = $this->find($path);

        return $backup === null ? null : Storage::disk(self::DISK)->download($backup['path']);
    }

    public function delete(string $path): bool
    {
        $backup = $this->find($path);

        if ($backup === null) {
            return false;
        }

        Storage::disk(self::DISK)->delete($backup['path']);
        $this->forgetStatus();

        SettingsAudit::record('Backups', 'backup deleted', ['file' => basename($backup['path'])]);

        return true;
    }

    /**
     * From the file name the backup package gives every archive
     * (e.g. 2026-11-22-02-00-01.zip), else the file's modified time.
     */
    private function backupDate(FileAttributes $file): CarbonInterface
    {
        try {
            $fromName = Carbon::createFromFormat(BackupJob::FILENAME_FORMAT, basename($file->path()));

            if ($fromName instanceof CarbonInterface) {
                return $fromName;
            }
        } catch (Throwable) {
            // Not a name the backup package wrote — fall back to the file time.
        }

        return Carbon::createFromTimestamp((int) $file->lastModified());
    }

    /**
     * Count / newest / total size, reused for STATUS_CACHE_MINUTES. Null when
     * nothing is saved. Throws if B2 can't be reached.
     *
     * @return BackupStatus|null
     */
    public function status(bool $fresh = false): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        if ($fresh) {
            $this->forgetStatus();
        }

        // Plain scalars only: the cache refuses to rebuild objects such as
        // Carbon dates (cache.serializable_classes = false), so dates are
        // cached as timestamps and turned back into dates here.
        $cached = Cache::remember(self::STATUS_CACHE_KEY, now()->addMinutes(self::STATUS_CACHE_MINUTES), function (): array {
            $backups = $this->backups();
            $newest = $backups->first();

            return [
                'count' => $backups->count(),
                'newestAt' => $newest === null ? null : $newest['date']->getTimestamp(),
                'totalBytes' => (int) $backups->sum('size'),
                'checkedAt' => now()->getTimestamp(),
            ];
        });

        return [
            'count' => $cached['count'],
            'newestAt' => $cached['newestAt'] === null ? null : Carbon::createFromTimestamp($cached['newestAt']),
            'totalBytes' => $cached['totalBytes'],
            'checkedAt' => Carbon::createFromTimestamp($cached['checkedAt']),
        ];
    }

    public function forgetStatus(): void
    {
        try {
            Cache::forget(self::STATUS_CACHE_KEY);
        } catch (Throwable) {
            // A cache outage only means the next status read lists B2 again.
        }
    }

    /**
     * @return BackupStorage|null
     */
    private function storage(): ?array
    {
        if ($this->storage === null) {
            try {
                $value = PlatformSetting::query()->where('key', self::SETTING_KEY)->first()?->value;
                $this->storage = is_array($value) && $this->isValid($value) ? $value : false;
            } catch (Throwable) {
                // Table missing or database down: behave as "not set up".
                $this->storage = false;
            }
        }

        return $this->storage === false ? null : $this->storage;
    }

    /**
     * @param  array<mixed>  $value
     *
     * @phpstan-assert-if-true BackupStorage $value
     */
    private function isValid(array $value): bool
    {
        foreach (['key_id', 'application_key', 'bucket', 'endpoint', 'password'] as $field) {
            if (! is_string($value[$field] ?? null) || $value[$field] === '') {
                return false;
            }
        }

        return true;
    }
}
