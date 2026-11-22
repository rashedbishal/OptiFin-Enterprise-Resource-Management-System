<?php

namespace App\Services\Logs;

use App\Enums\LogLevel;
use App\Enums\LogType;
use App\Support\Logs\LogFile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Where each entry of a log file starts, plus its level and time — so the
 * Logs page can count, filter and page through a big file without reading
 * it all every time. Stored as a small JSON file per log under
 * storage/framework/cache/log-index (git-ignored). When the log grows, only the new part is
 * read; when it was rotated, truncated or replaced, it is re-read from the
 * start.
 *
 * Each entry row: [offset, length, level, unix time|null, environment|null].
 *
 * @phpstan-type IndexRow array{0: int, 1: int, 2: string, 3: int|null, 4: string|null}
 */
class LogIndex
{
    private const VERSION = 1;

    /**
     * @param  string|null  $directory  where index files go (default storage/framework/cache/log-index; tests point it elsewhere)
     */
    public function __construct(private readonly ?string $directory = null) {}

    private const SIGNATURE_BYTES = 512;

    /**
     * "[2026-11-22 08:00:00] production.ERROR: …" (optionally with
     * fractions/offset) — Laravel's Monolog line format.
     */
    public const LARAVEL_HEADER = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?)\]\s+(\S+?)\.([A-Za-z]+):\s?/';

    private const WORKER_TIMESTAMP = '/^\s*(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/';

    private const WORKER_TAG = '/^\s*(INFO|WARN|WARNING|ERROR|FAIL|DONE)\s/';

    /**
     * @return list<IndexRow>
     */
    public function entries(LogFile $file): array
    {
        $stored = $this->load($file);
        $signature = $this->signature($file->path);

        if ($stored !== null && $stored['signature'] === $signature && $stored['size'] === $file->size) {
            return $stored['entries'];
        }

        $entries = [];
        $from = 0;

        // The file only grew: keep what was indexed, re-read from the start of
        // the last entry (it may have gained lines).
        if ($stored !== null && $stored['signature'] === $signature && $file->size > $stored['size'] && $stored['entries'] !== []) {
            $entries = $stored['entries'];
            $last = array_pop($entries);
            $from = $last[0];
        }

        $entries = [...$entries, ...$this->scan($file, $from)];

        $this->save($file, ['version' => self::VERSION, 'size' => $file->size, 'signature' => $signature, 'entries' => $entries]);

        return $entries;
    }

    public function forget(LogFile $file): void
    {
        File::delete($this->indexPath($file));
    }

    public function forgetAll(): void
    {
        File::deleteDirectory($this->directory());
    }

    public static function detectType(string $path): LogType
    {
        $handle = @fopen($path, 'rb');
        $head = $handle === false ? '' : (string) fread($handle, 4096);

        if ($handle !== false) {
            fclose($handle);
        }

        foreach (preg_split('/\R/', $head) ?: [] as $line) {
            if (preg_match(self::LARAVEL_HEADER, $line) === 1) {
                return LogType::Laravel;
            }
        }

        return preg_match('/(horizon|pulse|reverb|queue|worker|supervisor|schedule)/i', basename($path)) === 1 ? LogType::Worker : LogType::Generic;
    }

    /**
     * @return list<IndexRow>
     */
    private function scan(LogFile $file, int $from): array
    {
        $handle = @fopen($file->path, 'rb');

        if ($handle === false) {
            return [];
        }

        fseek($handle, $from);
        $entries = [];
        $current = null;
        $position = $from;

        while (($line = fgets($handle)) !== false) {
            $lineStart = $position;
            $position += strlen($line);
            $header = $this->header($file->type, $line);

            if ($header !== null || $current === null) {
                if ($current !== null) {
                    $current[1] = $lineStart - $current[0];
                    $entries[] = $current;
                }

                $current = [$lineStart, 0, ($header['level'] ?? LogLevel::Info)->value, $header['time'] ?? null, $header['env'] ?? null];
            }
        }

        fclose($handle);

        if ($current !== null) {
            $current[1] = $position - $current[0];
            $entries[] = $current;
        }

        return $entries;
    }

    /**
     * The entry header on this line, or null when the line continues the
     * previous entry (stack traces, wrapped messages).
     *
     * @return array{level: LogLevel, time: int|null, env: string|null}|null
     */
    private function header(LogType $type, string $line): ?array
    {
        if ($type === LogType::Laravel) {
            if (preg_match(self::LARAVEL_HEADER, $line, $matches) !== 1) {
                return null;
            }

            return ['level' => LogLevel::fromName($matches[3]), 'time' => $this->timestamp($matches[1]), 'env' => $matches[2]];
        }

        if (trim($line) === '') {
            return null;
        }

        if ($type === LogType::Generic) {
            return ['level' => $this->levelFromText($line), 'time' => null, 'env' => null];
        }

        $hasTime = preg_match(self::WORKER_TIMESTAMP, $line, $time) === 1;
        $hasTag = preg_match(self::WORKER_TAG, $line) === 1;
        $isIndented = preg_match('/^\s{4,}\S/', $line) === 1 && ! $hasTag;

        if (! $hasTime && ! $hasTag && $isIndented) {
            return null;
        }

        return ['level' => $this->levelFromText($line), 'time' => $hasTime ? $this->timestamp($time[1]) : null, 'env' => null];
    }

    private function levelFromText(string $line): LogLevel
    {
        return match (true) {
            preg_match('/\b(FAIL|FAILED|ERROR|FATAL|CRITICAL|Exception)\b/', $line) === 1 => LogLevel::Error,
            preg_match('/\bWARN(ING)?\b/i', $line) === 1 => LogLevel::Warning,
            preg_match('/\bDEBUG\b/', $line) === 1 => LogLevel::Debug,
            default => LogLevel::Info,
        };
    }

    private function timestamp(string $value): ?int
    {
        try {
            // Laravel writes times in the app timezone (UTC) without an offset.
            return CarbonImmutable::parse($value, 'UTC')->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    private function signature(string $path): string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        $head = (string) fread($handle, self::SIGNATURE_BYTES);
        fclose($handle);

        return md5($head);
    }

    /**
     * @return array{version: int, size: int, signature: string, entries: list<IndexRow>}|null
     */
    private function load(LogFile $file): ?array
    {
        $path = $this->indexPath($file);

        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || ($data['version'] ?? null) !== self::VERSION || ! is_array($data['entries'] ?? null)) {
            return null;
        }

        $entries = [];

        foreach ($data['entries'] as $row) {
            if (! is_array($row) || ! is_int($row[0] ?? null) || ! is_int($row[1] ?? null) || ! is_string($row[2] ?? null)) {
                return null;
            }

            $entries[] = [$row[0], $row[1], $row[2], is_int($row[3] ?? null) ? $row[3] : null, is_string($row[4] ?? null) ? $row[4] : null];
        }

        return ['version' => self::VERSION, 'size' => (int) ($data['size'] ?? 0), 'signature' => (string) ($data['signature'] ?? ''), 'entries' => $entries];
    }

    /**
     * @param  array{version: int, size: int, signature: string, entries: list<IndexRow>}  $data
     */
    private function save(LogFile $file, array $data): void
    {
        try {
            File::ensureDirectoryExists($this->directory());
            file_put_contents($this->indexPath($file), json_encode($data), LOCK_EX);
        } catch (Throwable) {
            // No index on disk only means the next visit reads the file again.
        }
    }

    private function indexPath(LogFile $file): string
    {
        return $this->directory().'/'.$file->id.'.json';
    }

    private function directory(): string
    {
        return $this->directory ?? storage_path('framework/cache/log-index');
    }
}
