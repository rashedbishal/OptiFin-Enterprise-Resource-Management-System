<?php

namespace App\Livewire\Platform\Monitoring;

use App\Enums\LogLevel;
use App\Enums\MonitoringTool;
use App\Enums\PermissionSlug;
use App\Livewire\Platform\Monitoring\Concerns\AuthorizesMonitoringTool;
use App\Services\Logs\LogReader;
use App\Support\Logs\LogEntry;
use App\Support\Logs\LogFile;
use App\Support\SettingsAudit;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\File;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin → Monitoring → Logs: the in-house log viewer (App\Services\Logs).
 * Files on the left, entries on the right: level filter with counts, search
 * (one file or all, optional regex), sorting, page size, live refresh, entry
 * details with stack trace / context / mail preview and a shareable link.
 * `view` on the Logs module reads; `update` also downloads and deletes.
 *
 * @phpstan-import-type IndexRow from \App\Services\Logs\LogIndex
 */
#[Title('Logs')]
#[Layout('layouts.admin')]
class Logs extends Component
{
    use AuthorizesMonitoringTool, WithPagination;

    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public const DISPLAY_TIMEZONE = 'Asia/Dhaka';

    #[Url]
    public string $file = '';

    /**
     * Selected level values, comma-separated (empty = all levels).
     */
    #[Url]
    public string $levels = '';

    #[Url]
    public string $search = '';

    #[Url]
    public bool $regex = false;

    #[Url]
    public bool $allFiles = false;

    #[Url]
    public string $sort = 'desc';

    #[Url]
    public int $perPage = 25;

    /**
     * Opened entry (its byte offset) — also the shareable link.
     */
    #[Url]
    public ?int $entry = null;

    public bool $live = false;

    public bool $showEntryModal = false;

    /**
     * File ids ticked for "Delete selected".
     *
     * @var list<string>
     */
    public array $selected = [];

    #[Locked]
    public ?string $confirmingDelete = null;

    public bool $showDeleteModal = false;

    public function mount(): void
    {
        $this->authorizeMonitoring();

        if (! in_array($this->perPage, self::PER_PAGE_OPTIONS, true)) {
            $this->perPage = 25;
        }

        if ($this->file === '' || $this->currentFile() === null) {
            $this->file = $this->files()[0]->id ?? '';
            unset($this->currentFile);
        }

        // A shared link (…?file=…&entry=…) opens that entry straight away.
        $this->showEntryModal = $this->entry !== null && $this->opened() !== null;

        if (! $this->showEntryModal) {
            $this->entry = null;
        }
    }

    public function updatedShowEntryModal(bool $open): void
    {
        if (! $open) {
            $this->entry = null;
        }
    }

    protected function monitoringTool(): MonitoringTool
    {
        return MonitoringTool::Logs;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'regex', 'allFiles', 'sort', 'perPage', 'levels'], true)) {
            if (! in_array($this->perPage, self::PER_PAGE_OPTIONS, true)) {
                $this->perPage = 25;
            }

            $this->sort = $this->sort === 'asc' ? 'asc' : 'desc';
            $this->resetPage();
        }
    }

    #[Computed]
    public function canManage(): bool
    {
        return $this->allowsMonitoring(PermissionSlug::Update);
    }

    /**
     * @return list<LogFile>
     */
    #[Computed]
    public function files(): array
    {
        return app(LogReader::class)->files();
    }

    /**
     * @return array<string, list<LogFile>> folder ('' = top level) => files
     */
    #[Computed]
    public function folders(): array
    {
        $folders = [];

        foreach ($this->files() as $file) {
            $folders[$file->folder][] = $file;
        }

        return $folders;
    }

    #[Computed]
    public function currentFile(): ?LogFile
    {
        foreach ($this->files() as $file) {
            if ($file->id === $this->file) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return list<LogLevel>
     */
    public function selectedLevels(): array
    {
        return array_values(array_filter(array_map(
            fn (string $value): ?LogLevel => LogLevel::tryFrom($value),
            array_filter(explode(',', $this->levels)),
        )));
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function levelCounts(): array
    {
        $file = $this->currentFile();

        return $file === null ? [] : app(LogReader::class)->levelCounts($file);
    }

    /**
     * @return array{paginator: LengthAwarePaginator<int, LogEntry>, error: string|null}
     */
    #[Computed]
    public function results(): array
    {
        $file = $this->currentFile();
        $files = $this->allFiles ? $this->files() : ($file === null ? [] : [$file]);
        $page = $this->getPage();

        $result = app(LogReader::class)->page($files, $this->selectedLevels(), trim($this->search), $this->regex, $this->sort !== 'asc', $page, $this->perPage);

        return [
            'paginator' => new LengthAwarePaginator($result['entries'], $result['total'], $this->perPage, $page),
            'error' => $result['error'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function opened(): ?array
    {
        if ($this->entry === null) {
            return null;
        }

        $file = $this->currentFile();

        return $file === null ? null : app(LogReader::class)->open($file, $this->entry);
    }

    public function selectFile(string $id): void
    {
        $this->file = $id;
        $this->entry = null;
        $this->allFiles = false;
        $this->resetPage();
        unset($this->currentFile, $this->levelCounts, $this->results, $this->opened);
    }

    public function toggleLevel(string $value): void
    {
        if (LogLevel::tryFrom($value) === null) {
            return;
        }

        $current = array_map(fn (LogLevel $level): string => $level->value, $this->selectedLevels());
        $current = in_array($value, $current, true) ? array_values(array_diff($current, [$value])) : [...$current, $value];

        $this->levels = implode(',', $current);
        $this->resetPage();
    }

    public function clearLevels(): void
    {
        $this->levels = '';
        $this->resetPage();
    }

    public function openEntry(string $fileId, int $offset): void
    {
        $this->file = $fileId;
        $this->entry = $offset;
        unset($this->currentFile, $this->opened);

        if ($this->opened() === null) {
            $this->entry = null;
            Flux::toast(variant: 'warning', text: __('That entry is no longer in the file (it may have been rotated or deleted).'));

            return;
        }

        $this->showEntryModal = true;
    }

    public function closeEntry(): void
    {
        $this->entry = null;
        $this->showEntryModal = false;
    }

    /**
     * Live mode's 10-second poll: re-read the newest entries.
     */
    public function refreshEntries(): void
    {
        unset($this->files, $this->folders, $this->currentFile, $this->levelCounts, $this->results);
    }

    /**
     * Drops every saved index so all files are read again from scratch.
     */
    public function rescan(): void
    {
        app(LogReader::class)->forgetIndexes();
        $this->refreshEntries();

        Flux::toast(variant: 'success', text: __('Log files rescanned.'));
    }

    public function toggleSelected(string $id): void
    {
        $this->selected = in_array($id, $this->selected, true)
            ? array_values(array_diff($this->selected, [$id]))
            : [...$this->selected, $id];
    }

    public function confirmDelete(string $target): void
    {
        $this->authorizeMonitoring(PermissionSlug::Update);

        $this->confirmingDelete = $target;
        $this->showDeleteModal = true;
    }

    /**
     * Deletes the confirmed file, folder or selection — only files this page
     * listed under storage/logs — and records it in the Activity log.
     */
    public function deleteConfirmed(): void
    {
        $this->authorizeMonitoring(PermissionSlug::Update);

        $targets = $this->deleteTargets((string) $this->confirmingDelete);
        $reader = app(LogReader::class);

        foreach ($targets as $file) {
            $reader->forgetIndex($file);
            File::delete($file->path);
        }

        if ($targets !== []) {
            SettingsAudit::record('Logs', count($targets) === 1 ? 'log file deleted' : 'log files deleted', [
                'files' => implode(', ', array_map(fn (LogFile $file): string => $file->relativePath, $targets)),
                'count' => count($targets),
            ]);
        }

        $this->showDeleteModal = false;
        $this->confirmingDelete = null;
        unset($this->pendingDelete);
        $this->selected = [];
        $this->entry = null;
        $this->refreshEntries();

        if ($this->currentFile() === null) {
            $this->file = $this->files()[0]->id ?? '';
            unset($this->currentFile);
        }

        Flux::toast(variant: 'success', text: trans_choice(':count log file deleted.|:count log files deleted.', count($targets)));
    }

    /**
     * The files the open delete confirmation is about (for its wording).
     *
     * @return list<LogFile>
     */
    #[Computed]
    public function pendingDelete(): array
    {
        return $this->confirmingDelete === null ? [] : $this->deleteTargets($this->confirmingDelete);
    }

    /**
     * @return list<LogFile>
     */
    private function deleteTargets(string $target): array
    {
        [$kind, $value] = array_pad(explode(':', $target, 2), 2, '');

        return array_values(array_filter($this->files(), fn (LogFile $file): bool => match ($kind) {
            'file' => $file->id === $value,
            // Real sub-folders only; the top level is never deleted as a "folder".
            'folder' => $value !== '' && ($file->folder === $value || str_starts_with($file->folder, $value.DIRECTORY_SEPARATOR)),
            'selected' => in_array($file->id, $this->selected, true),
            default => false,
        }));
    }
}
