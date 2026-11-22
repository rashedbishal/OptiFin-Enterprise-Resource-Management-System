<?php

namespace App\Services\Logs;

use App\Support\Logs\LogFile;
use RuntimeException;

/**
 * Deletes entries from, or empties, a log file for Admin → Monitoring → Logs
 * — always IN PLACE on the same file (never delete + recreate), because the
 * app and the workers keep it open and keep writing to it. Done under an
 * exclusive lock; the app's own log channels lock on every write
 * (config/logging.php 'locking'), so they briefly wait instead of losing a
 * line. The .log file itself is never deleted.
 *
 * @phpstan-import-type IndexRow from LogIndex
 */
class LogEditor
{
    private const COPY_CHUNK = 1_048_576;

    public function __construct(private readonly LogIndex $index) {}

    /**
     * Removes the entries starting at these byte offsets. Offsets that are
     * not (or no longer) the start of an entry are ignored.
     *
     * @param  list<int>  $offsets
     * @return int how many entries were removed
     */
    public function deleteEntries(LogFile $file, array $offsets): int
    {
        return $this->locked($file, function ($handle) use ($file, $offsets): int {
            // Fresh index, read while holding the lock.
            $this->index->forget($file);
            $wanted = array_flip($offsets);
            $ranges = [];

            foreach ($this->index->entries($this->refreshed($file)) as $row) {
                if (isset($wanted[$row[0]])) {
                    $ranges[] = [$row[0], $row[0] + $row[1]];
                }
            }

            if ($ranges === []) {
                return 0;
            }

            usort($ranges, fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $this->rewriteWithout($handle, $ranges);
            $this->index->forget($file);

            return count($ranges);
        });
    }

    public function empty(LogFile $file): void
    {
        $this->locked($file, function ($handle) use ($file): void {
            ftruncate($handle, 0);
            $this->index->forget($file);
        });
    }

    /**
     * Copies everything after the first removed range — minus the removed
     * ranges — to a temporary buffer, truncates the file there and writes the
     * kept bytes back. The bytes before the first range are never touched.
     *
     * @param  list<array{0: int, 1: int}>  $ranges  sorted, non-overlapping [start, end)
     * @param  resource  $handle
     */
    private function rewriteWithout($handle, array $ranges): void
    {
        $start = max(0, $ranges[0][0]);
        $stat = fstat($handle);

        if ($stat === false) {
            throw new RuntimeException('The log file could not be read.');
        }

        $size = (int) $stat['size'];
        $buffer = fopen('php://temp/maxmemory:'.(8 * 1024 * 1024), 'w+b');

        if ($buffer === false) {
            throw new RuntimeException('Could not open a temporary buffer.');
        }

        $position = $start;

        foreach ([...$ranges, [$size, $size]] as [$from, $to]) {
            $this->copy($handle, $buffer, $position, min($from, $size) - $position);
            $position = max($position, min($to, $size));
        }

        ftruncate($handle, $start);
        rewind($buffer);
        fseek($handle, $start);
        stream_copy_to_stream($buffer, $handle);
        fflush($handle);
        fclose($buffer);
    }

    /**
     * @param  resource  $from
     * @param  resource  $to
     */
    private function copy($from, $to, int $offset, int $length): void
    {
        if ($length <= 0) {
            return;
        }

        fseek($from, $offset);

        while ($length > 0) {
            $chunk = fread($from, min(self::COPY_CHUNK, $length));

            if ($chunk === false || $chunk === '') {
                return;
            }

            fwrite($to, $chunk);
            $length -= strlen($chunk);
        }
    }

    /**
     * @template T
     *
     * @param  callable(resource): T  $callback
     * @return T
     */
    private function locked(LogFile $file, callable $callback): mixed
    {
        $handle = @fopen($file->path, 'r+b');

        if ($handle === false) {
            throw new RuntimeException('The log file could not be opened for editing.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('The log file could not be locked.');
            }

            return $callback($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            clearstatcache(true, $file->path);
        }
    }

    /**
     * The same file with its current size (it may have grown since listing).
     */
    private function refreshed(LogFile $file): LogFile
    {
        clearstatcache(true, $file->path);

        return new LogFile($file->id, $file->path, $file->relativePath, $file->folder, $file->name, (int) filesize($file->path), $file->modifiedAt, $file->type);
    }
}
