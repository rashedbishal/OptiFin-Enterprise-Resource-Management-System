<?php

namespace App\Services\Logs;

use App\Enums\LogLevel;
use App\Enums\LogType;
use App\Support\Logs\LogEntry;
use App\Support\Logs\LogFile;
use Carbon\CarbonImmutable;
use FilesystemIterator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The in-house log reader behind Admin → Monitoring → Logs: lists the .log
 * files under storage/logs, counts/filters/searches/pages their entries
 * (via LogIndex) and opens one entry in full.
 *
 * @phpstan-import-type IndexRow from LogIndex
 */
class LogReader
{
    private const PREVIEW_CHARS = 300;

    /**
     * An opened entry is read up to this size (a runaway stack trace).
     */
    private const MAX_ENTRY_BYTES = 2_000_000;

    /**
     * Search only looks at this much of each entry.
     */
    private const SEARCH_BYTES = 200_000;

    /**
     * @var array<string, list<IndexRow>>
     */
    private array $indexes = [];

    /**
     * @param  string|null  $root  the folder to read (default storage/logs; tests point it elsewhere)
     */
    public function __construct(private readonly LogIndex $index, private readonly MailPreviewParser $mail, private readonly ?string $root = null) {}

    public function root(): string
    {
        return $this->root ?? storage_path('logs');
    }

    /**
     * Every .log file under storage/logs, folders first-level grouped, newest
     * change first within a folder.
     *
     * @return list<LogFile>
     */
    public function files(): array
    {
        $root = $this->root();

        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $info) {
            if ($info instanceof SplFileInfo && $info->isFile() && strtolower($info->getExtension()) === 'log') {
                $files[] = $this->describe($info->getPathname());
            }
        }

        usort($files, fn (LogFile $a, LogFile $b): int => [$a->folder, -$a->modifiedAt->getTimestamp()] <=> [$b->folder, -$b->modifiedAt->getTimestamp()]);

        return $files;
    }

    public function find(string $id): ?LogFile
    {
        foreach ($this->files() as $file) {
            if ($file->id === $id) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return array<string, int> level value => count
     */
    public function levelCounts(LogFile $file): array
    {
        $counts = [];

        foreach ($this->rows($file) as $row) {
            $counts[$row[2]] = ($counts[$row[2]] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * One page of entries from one or more files.
     *
     * @param  list<LogFile>  $files
     * @param  list<LogLevel>  $levels  empty = every level
     * @return array{entries: list<LogEntry>, total: int, error: string|null}
     */
    public function page(array $files, array $levels, string $search, bool $regex, bool $newestFirst, int $page, int $perPage): array
    {
        $pattern = null;

        if ($search !== '') {
            $pattern = $regex ? $this->regexPattern($search) : null;

            if ($regex && $pattern === null) {
                return ['entries' => [], 'total' => 0, 'error' => __('That is not a valid regular expression.')];
            }
        }

        $wanted = array_map(fn (LogLevel $level): string => $level->value, $levels);
        $matches = [];

        foreach ($files as $fileOrder => $file) {
            $rows = array_values(array_filter($this->rows($file), fn (array $row): bool => $wanted === [] || in_array($row[2], $wanted, true)));

            if ($search !== '') {
                $rows = $this->search($file, $rows, $search, $pattern);
            }

            foreach ($rows as $row) {
                // Oldest first: by time when known, then file, then position.
                $matches[] = ['file' => $file, 'row' => $row, 'key' => [$row[3] ?? 0, $fileOrder, $row[0]]];
            }
        }

        usort($matches, fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        if ($newestFirst) {
            $matches = array_reverse($matches);
        }

        $slice = array_slice($matches, max(0, ($page - 1) * $perPage), $perPage);

        return [
            'entries' => array_map(fn (array $match): LogEntry => $this->entry($match['file'], $match['row']), $slice),
            'total' => count($matches),
            'error' => null,
        ];
    }

    /**
     * One entry, in full, parsed for display. Null when $offset is not the
     * start of an entry of this file (stale link, file changed).
     *
     * @return array{entry: LogEntry, text: string, message: string, exception: string|null, trace: list<string>, context: string|null, mail: array{subject: string, from: string, to: string, date: string, html: string|null, text: string|null}|null, truncated: bool}|null
     */
    public function open(LogFile $file, int $offset): ?array
    {
        foreach ($this->rows($file) as $row) {
            if ($row[0] === $offset) {
                $text = $this->read($file, $row[0], min($row[1], self::MAX_ENTRY_BYTES));

                return [
                    'entry' => $this->entry($file, $row),
                    'text' => $text,
                    'truncated' => $row[1] > self::MAX_ENTRY_BYTES,
                    ...$this->parse($file->type, $text),
                ];
            }
        }

        return null;
    }

    public function forgetIndexes(): void
    {
        $this->index->forgetAll();
        $this->indexes = [];
    }

    public function forgetIndex(LogFile $file): void
    {
        $this->index->forget($file);
        $this->indexes = [];
    }

    /**
     * Splits an entry into message / exception / stack trace / context /
     * emailed message.
     *
     * @return array{message: string, exception: string|null, trace: list<string>, context: string|null, mail: array{subject: string, from: string, to: string, date: string, html: string|null, text: string|null}|null}
     */
    private function parse(LogType $type, string $text): array
    {
        $body = $type === LogType::Laravel ? (string) preg_replace(LogIndex::LARAVEL_HEADER, '', $text, 1) : $text;
        $body = rtrim($body);
        $exception = null;
        $trace = [];
        $context = null;

        // Laravel exception: `message {"exception":"[object] (Class(code: 0): msg at file:line)
        // [stacktrace]
        // #0 …
        // "} `
        if (preg_match('/^(.*?)\s*\{"exception":"\[object\] \((.*?)\)\R\[stacktrace\]\R(.*?)\R?"\}\s*(\[\]|\{.*\})?\s*$/s', $body, $matches) === 1) {
            $message = trim($matches[1]);
            $exception = stripcslashes($matches[2]);
            $trace = array_values(array_filter(preg_split('/\R/', $matches[3]) ?: [], fn (string $line): bool => trim($line) !== ''));
        } elseif (preg_match('/^(.*?)\s(\{.*\}|\[.*\])\s(\[\]|\{.*\})$/s', $body, $matches) === 1 && json_decode($matches[2]) !== null) {
            $message = trim($matches[1]);
            $decoded = json_decode($matches[2], true);
            $context = $decoded === [] ? null : (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $message = $body;
        }

        return [
            'message' => $message,
            'exception' => $exception,
            'trace' => $trace,
            'context' => $context,
            'mail' => $type === LogType::Laravel ? $this->mail->parse($message) : null,
        ];
    }

    /**
     * @param  list<IndexRow>  $rows
     * @return list<IndexRow>
     */
    private function search(LogFile $file, array $rows, string $search, ?string $pattern): array
    {
        $handle = @fopen($file->path, 'rb');

        if ($handle === false) {
            return [];
        }

        $found = [];

        foreach ($rows as $row) {
            fseek($handle, $row[0]);
            $text = (string) fread($handle, max(1, min($row[1], self::SEARCH_BYTES)));

            $hit = $pattern !== null ? preg_match($pattern, $text) === 1 : Str::contains($text, $search, ignoreCase: true);

            if ($hit) {
                $found[] = $row;
            }
        }

        fclose($handle);

        return $found;
    }

    /**
     * @param  IndexRow  $row
     */
    private function entry(LogFile $file, array $row): LogEntry
    {
        $first = $this->read($file, $row[0], min($row[1], 4096));
        $line = Str::before(str_replace("\r", '', $first), "\n");

        if ($file->type === LogType::Laravel) {
            $line = (string) preg_replace(LogIndex::LARAVEL_HEADER, '', $line, 1);
            // Drop the technical tail (exception object / context JSON) from the preview.
            $line = (string) preg_replace('/\s\{"exception":.*$/', '', $line);
            $line = (string) preg_replace('/\s(\[\]|\{.*\})\s(\[\]|\{.*\})$/', '', $line);
        }

        return new LogEntry(
            fileId: $file->id,
            fileName: $file->relativePath,
            offset: $row[0],
            length: $row[1],
            level: LogLevel::from($row[2]),
            time: $row[3] === null ? null : CarbonImmutable::createFromTimestamp($row[3]),
            environment: $row[4],
            preview: Str::limit(trim($line), self::PREVIEW_CHARS),
            hasMoreLines: str_contains(rtrim($first), "\n"),
        );
    }

    private function read(LogFile $file, int $offset, int $length): string
    {
        $handle = @fopen($file->path, 'rb');

        if ($handle === false || $length <= 0) {
            return '';
        }

        fseek($handle, $offset);
        $text = (string) fread($handle, $length);
        fclose($handle);

        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    /**
     * @return list<IndexRow>
     */
    private function rows(LogFile $file): array
    {
        // Keyed by size + modification time too, so a file that changed since
        // it was last read (this object may live long) is read again.
        return $this->indexes[$file->id.'|'.$file->size.'|'.$file->modifiedAt->getTimestamp()] ??= $this->index->entries($file);
    }

    private function regexPattern(string $search): ?string
    {
        $pattern = '~'.str_replace('~', '\~', $search).'~iu';

        return @preg_match($pattern, '') === false ? null : $pattern;
    }

    private function describe(string $path): LogFile
    {
        $relative = ltrim(Str::after($path, $this->root()), DIRECTORY_SEPARATOR);
        $folder = str_contains($relative, DIRECTORY_SEPARATOR) ? dirname($relative) : '';

        return new LogFile(
            id: LogFile::idFor($relative),
            path: $path,
            relativePath: $relative,
            folder: $folder,
            name: basename($relative),
            size: (int) File::size($path),
            modifiedAt: CarbonImmutable::createFromTimestamp((int) File::lastModified($path)),
            type: LogIndex::detectType($path),
        );
    }
}
