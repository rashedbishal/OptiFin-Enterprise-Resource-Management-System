<?php

namespace App\Services;

use App\Enums\LeadSourceType;
use App\Models\LeadSource;
use App\Models\PlatformSetting;
use App\Models\PlatformUser;
use App\Models\TenantUser;
use App\Support\ApkManifestReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * The OptiFin Calling app's downloadable Android APK — ONE platform-wide
 * file (latest only), uploaded by the Mother Admin on Admin → Calling app and
 * downloaded by eligible tenant users from Settings → Calling app.
 *
 * Uploads arrive in small chunks (see CHUNK_BYTES) so a ~90 MB APK never
 * trips a server's PHP/nginx request-size limit; complete() reassembles,
 * checks it really is THE OptiFin Calling app, and swaps it in.
 *
 * The version is always READ FROM THE APK (ApkManifestReader), never typed:
 * a mislabelled upload once made every phone say "Update available" forever,
 * because the app compares its own real version against this one. An upload
 * with a lower build number (versionCode) than the current one is refused,
 * so phones can never be told to "update" to an older app. The file lives on
 * the private `local` disk — never under public/ — and is only ever streamed
 * through an authenticated, eligibility-checked route.
 *
 * Eligible downloaders (approved): users of a tenant with a WhatsApp lead
 * source that has calling switched on — the app is useless anywhere else.
 */
class CallingAppRelease
{
    public const SETTING_KEY = 'calling_app.android_release';

    /** Under nginx's 1 MB default body limit, with room for headers. */
    public const CHUNK_BYTES = 786432;

    public const MAX_BYTES = 200 * 1024 * 1024;

    private const DIR = 'calling-app';

    private const UPLOADS_DIR = 'calling-app/uploads';

    /**
     * @return array{version: string, version_code: int|null, notes: string|null, size: int, sha256: string, path: string, uploaded_at: Carbon|null, uploaded_by: string|null}|null
     */
    public function current(): ?array
    {
        $setting = PlatformSetting::query()->with('updatedBy:id,name')->where('key', self::SETTING_KEY)->first();
        $release = $setting?->value;

        if (! is_array($release) || ! is_string($release['path'] ?? null) || ! Storage::disk('local')->exists($release['path'])) {
            return null;
        }

        return [
            'version' => (string) ($release['version'] ?? ''),
            'version_code' => is_int($release['version_code'] ?? null) ? $release['version_code'] : null,
            'notes' => isset($release['notes']) && is_string($release['notes']) ? $release['notes'] : null,
            'size' => (int) ($release['size'] ?? 0),
            'sha256' => (string) ($release['sha256'] ?? ''),
            'path' => $release['path'],
            'uploaded_at' => $setting->updated_at,
            'uploaded_by' => $setting->updatedBy?->name,
        ];
    }

    public static function isValidVersion(mixed $version): bool
    {
        return is_string($version) && preg_match('/^\d{1,4}(\.\d{1,4}){1,3}$/', $version) === 1;
    }

    /**
     * Starts a chunked upload; returns its id. Abandoned uploads older than a
     * day are swept here, so nothing needs a scheduled job.
     */
    public function startUpload(int $size, ?string $notes): string
    {
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new RuntimeException(__('The file must be between 1 byte and :mb MB.', ['mb' => self::MAX_BYTES / 1024 / 1024]));
        }

        $this->sweepAbandonedUploads();

        $id = Str::lower((string) Str::ulid());
        $disk = Storage::disk('local');
        $disk->makeDirectory($this->uploadDir($id));
        $disk->put($this->uploadDir($id).'/meta.json', (string) json_encode([
            'size' => $size,
            'notes' => $notes,
            'chunks' => (int) ceil($size / self::CHUNK_BYTES),
        ]));

        return $id;
    }

    /**
     * Stores one chunk (idempotent: a retried chunk just overwrites itself).
     */
    public function putChunk(string $uploadId, int $index, string $bytes): void
    {
        $meta = $this->meta($uploadId);

        if ($index < 0 || $index >= $meta['chunks']) {
            throw new RuntimeException(__('Unexpected chunk number.'));
        }

        $isLast = $index === $meta['chunks'] - 1;
        $expected = $isLast ? $meta['size'] - (self::CHUNK_BYTES * ($meta['chunks'] - 1)) : self::CHUNK_BYTES;

        if (strlen($bytes) !== $expected) {
            throw new RuntimeException(__('Chunk :n arrived damaged — please try the upload again.', ['n' => $index + 1]));
        }

        Storage::disk('local')->put($this->uploadDir($uploadId).'/'.$index.'.part', $bytes);
    }

    /**
     * Reassembles the chunks, verifies the result is the OptiFin Calling app
     * and not older than the current release, and makes it current (deleting
     * the previous file).
     *
     * @return array{version: string, version_code: int, size: int, sha256: string}
     */
    public function completeUpload(string $uploadId, PlatformUser $by): array
    {
        $meta = $this->meta($uploadId);
        $disk = Storage::disk('local');
        $dir = $this->uploadDir($uploadId);
        $assembled = $disk->path($dir.'/assembled.apk');

        $out = fopen($assembled, 'wb');

        if ($out === false) {
            throw new RuntimeException(__('Could not write the uploaded file.'));
        }

        try {
            for ($i = 0; $i < $meta['chunks']; $i++) {
                if (! $disk->exists($dir.'/'.$i.'.part')) {
                    throw new RuntimeException(__('Part of the upload is missing — please try again.'));
                }

                fwrite($out, (string) $disk->get($dir.'/'.$i.'.part'));
            }
        } finally {
            fclose($out);
        }

        if (filesize($assembled) !== $meta['size']) {
            $disk->deleteDirectory($dir);

            throw new RuntimeException(__('The uploaded file is incomplete — please try again.'));
        }

        if (! self::looksLikeApk($assembled)) {
            $disk->deleteDirectory($dir);

            throw new RuntimeException(__('This is not an Android app (.apk) file.'));
        }

        $identity = ApkManifestReader::fromApk($assembled);
        $expectedPackage = (string) config('calling.calling_app.android_package');
        $previous = $this->current();

        if ($identity === null || ! self::isValidVersion($identity['version_name']) || $identity['version_code'] === null) {
            $disk->deleteDirectory($dir);

            throw new RuntimeException(__('Could not read the app version from this file.'));
        }

        if ($identity['package'] !== $expectedPackage) {
            $disk->deleteDirectory($dir);

            throw new RuntimeException(__('This is not the OptiFin Calling app (package ":package").', ['package' => (string) $identity['package']]));
        }

        if ($previous !== null && $previous['version_code'] !== null && $identity['version_code'] < $previous['version_code']) {
            $disk->deleteDirectory($dir);

            throw new RuntimeException(__('This file is version :new, older than the published version :current.', [
                'new' => (string) $identity['version_name'],
                'current' => $previous['version'],
            ]));
        }

        $version = (string) $identity['version_name'];
        $versionCode = (int) $identity['version_code'];
        $sha256 = (string) hash_file('sha256', $assembled);
        $finalPath = self::DIR.'/optifin-calling-'.$version.'-'.substr($sha256, 0, 8).'.apk';

        $disk->delete($finalPath);
        $disk->move($dir.'/assembled.apk', $finalPath);
        $disk->deleteDirectory($dir);

        PlatformSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => [
                'path' => $finalPath,
                'version' => $version,
                'version_code' => $versionCode,
                'package' => $identity['package'],
                'notes' => $meta['notes'],
                'size' => $meta['size'],
                'sha256' => $sha256,
            ], 'updated_by' => $by->id],
        );

        if ($previous !== null && $previous['path'] !== $finalPath) {
            $disk->delete($previous['path']);
        }

        return ['version' => $version, 'version_code' => $versionCode, 'size' => $meta['size'], 'sha256' => $sha256];
    }

    public function remove(): void
    {
        $current = $this->current();

        if ($current !== null) {
            Storage::disk('local')->delete($current['path']);
        }

        PlatformSetting::query()->where('key', self::SETTING_KEY)->delete();
    }

    /**
     * A tenant user may see/download the app only when their tenant has a
     * WhatsApp lead source with calling switched on (approved rule). Module
     * access is enforced separately by the routes' module:lead-management.
     */
    public static function tenantMayDownload(TenantUser $user): bool
    {
        return LeadSource::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('type', LeadSourceType::Whatsapp->value)
            ->where('is_active', true)
            ->where('calling_enabled', true)
            ->exists();
    }

    /**
     * An APK is a ZIP containing AndroidManifest.xml and classes.dex.
     */
    public static function looksLikeApk(string $path): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        $ok = $zip->locateName('AndroidManifest.xml') !== false && $zip->locateName('classes.dex') !== false;
        $zip->close();

        return $ok;
    }

    /**
     * @return array{size: int, notes: string|null, chunks: int}
     */
    private function meta(string $uploadId): array
    {
        if (preg_match('/^[0-9a-z]{26}$/', $uploadId) !== 1) {
            throw new RuntimeException(__('Unknown upload.'));
        }

        $raw = Storage::disk('local')->get($this->uploadDir($uploadId).'/meta.json');
        $meta = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($meta)) {
            throw new RuntimeException(__('Unknown upload.'));
        }

        return [
            'size' => (int) $meta['size'],
            'notes' => isset($meta['notes']) && is_string($meta['notes']) ? $meta['notes'] : null,
            'chunks' => (int) $meta['chunks'],
        ];
    }

    private function uploadDir(string $uploadId): string
    {
        return self::UPLOADS_DIR.'/'.$uploadId;
    }

    private function sweepAbandonedUploads(): void
    {
        $disk = Storage::disk('local');
        $cutoff = Carbon::now()->subDay()->getTimestamp();

        foreach ($disk->directories(self::UPLOADS_DIR) as $dir) {
            $meta = $dir.'/meta.json';

            if (! $disk->exists($meta) || $disk->lastModified($meta) < $cutoff) {
                $disk->deleteDirectory($dir);
            }
        }
    }
}
