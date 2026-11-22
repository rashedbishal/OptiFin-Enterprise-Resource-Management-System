@php
    use App\Enums\LogLevel;
    use App\Livewire\Platform\Monitoring\Logs;

    $current = $this->currentFile;
    $counts = $this->levelCounts;
    $results = $this->results;
    $paginator = $results['paginator'];
    $selectedLevels = array_map(fn ($level) => $level->value, $this->selectedLevels());
    $tz = Logs::DISPLAY_TIMEZONE;
    $size = function (int $bytes): string {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return ($unit === 'B' ? $bytes : number_format($bytes, 1)).' '.$unit;
            }
            $bytes /= 1024;
        }

        return '';
    };
    $sortOptions = [['value' => 'desc', 'label' => __('Newest first')], ['value' => 'asc', 'label' => __('Oldest first')]];
    $perPageOptions = array_map(fn ($n) => ['value' => (string) $n, 'label' => __(':n per page', ['n' => $n])], Logs::PER_PAGE_OPTIONS);
@endphp

<div class="flex flex-1 flex-col gap-6" @if ($live) wire:poll.10s="refreshEntries" @endif>
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('admin.dashboard')],
        ['label' => __('Monitoring')],
        ['label' => __('Logs')],
    ]" />

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Logs') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Everything the application writes to its log files. Times are shown in Bangladesh time.') }}</flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <flux:button size="sm" :variant="$live ? 'primary' : 'outline'" :icon="$live ? 'pause' : 'play'" wire:click="$toggle('live')" data-test="live-toggle">
                {{ $live ? __('Live · every 10s') : __('Live') }}
            </flux:button>
            <flux:button size="sm" variant="outline" icon="arrow-path" wire:click="rescan" wire:loading.attr="disabled" wire:target="rescan">{{ __('Rescan files') }}</flux:button>
            @if ($this->canManage && count($this->files) > 0)
                <flux:button size="sm" variant="danger" icon="no-symbol" wire:click="confirmEmpty('all')" data-test="empty-all">{{ __('Empty all files') }}</flux:button>
            @endif
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-[19rem_minmax(0,1fr)]">
        {{-- ── Files ─────────────────────────────────────────────── --}}
        <flux:card class="flex h-fit flex-col gap-4 p-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Files') }}</flux:heading>
                <flux:badge size="sm">{{ count($this->files) }}</flux:badge>
            </div>

            @forelse ($this->folders as $folder => $files)
                <div class="flex flex-col gap-1" wire:key="folder-{{ md5($folder) }}">
                    @if ($folder !== '')
                        <div class="flex items-center justify-between gap-2 px-1 pt-2">
                            <div class="flex min-w-0 items-center gap-1.5 text-xs font-semibold tracking-wide text-zinc-500 uppercase">
                                <flux:icon.folder variant="micro" />
                                <span class="truncate">{{ $folder }}</span>
                            </div>
                            @if ($this->canManage)
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Folder actions')" />
                                    <flux:menu>
                                        <flux:menu.item icon="arrow-down-tray" :href="route('admin.monitoring.logs.download-folder', $folder)">{{ __('Download folder (.zip)') }}</flux:menu.item>
                                        <flux:menu.item icon="no-symbol" variant="danger" wire:click="confirmEmpty('folder:{{ base64_encode($folder) }}')">{{ __('Empty folder') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        </div>
                    @endif

                    @foreach ($files as $logFile)
                        @php($active = $current?->id === $logFile->id && ! $allFiles)
                        <div wire:key="file-{{ $logFile->id }}" @class([
                            'group flex items-center gap-2 rounded-lg px-2 py-2 transition',
                            'bg-zinc-100 dark:bg-white/10' => $active,
                            'hover:bg-zinc-50 dark:hover:bg-white/5' => ! $active,
                        ])>
                            @if ($this->canManage)
                                <flux:checkbox :checked="in_array($logFile->id, $selected, true)" wire:click="toggleSelected('{{ $logFile->id }}')" :aria-label="__('Select :name', ['name' => $logFile->name])" />
                            @endif
                            <button type="button" class="min-w-0 flex-1 text-start" wire:click="selectFile('{{ $logFile->id }}')" data-test="log-file">
                                <div @class(['truncate text-sm', 'font-semibold text-zinc-900 dark:text-white' => $active, 'text-zinc-700 dark:text-zinc-200' => ! $active])>{{ $logFile->name }}</div>
                                <div class="text-xs text-zinc-500">{{ $size($logFile->size) }} · {{ $logFile->modifiedAt->timezone($tz)->diffForHumans() }}</div>
                            </button>
                            @if ($this->canManage)
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="xs" variant="ghost" icon="ellipsis-vertical" :aria-label="__('File actions')" />
                                    <flux:menu>
                                        <flux:menu.item icon="arrow-down-tray" :href="route('admin.monitoring.logs.download-file', $logFile->id)">{{ __('Download') }}</flux:menu.item>
                                        <flux:menu.item icon="no-symbol" variant="danger" wire:click="confirmEmpty('file:{{ $logFile->id }}')">{{ __('Empty file') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        </div>
                    @endforeach
                </div>
            @empty
                <flux:text>{{ __('No log files yet.') }}</flux:text>
            @endforelse

            @if ($this->canManage && $selected !== [])
                <flux:button variant="danger" size="sm" icon="no-symbol" wire:click="confirmEmpty('selected')">{{ trans_choice('Empty :count selected file|Empty :count selected files', count($selected)) }}</flux:button>
            @endif
        </flux:card>

        {{-- ── Entries ───────────────────────────────────────────── --}}
        <div class="flex min-w-0 flex-col gap-4">
            <flux:card class="flex flex-col gap-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        @if ($allFiles)
                            <flux:heading size="lg">{{ __('All files') }}</flux:heading>
                            <flux:text class="text-sm">{{ __('Searching every log file.') }}</flux:text>
                        @elseif ($current)
                            <div class="flex items-center gap-2">
                                <flux:heading size="lg" class="truncate">{{ $current->relativePath }}</flux:heading>
                                <flux:badge size="sm">{{ $current->type->label() }}</flux:badge>
                            </div>
                            <flux:text class="text-sm">{{ $size($current->size) }} · {{ __('last written :time', ['time' => $current->modifiedAt->timezone($tz)->diffForHumans()]) }}</flux:text>
                        @else
                            <flux:heading size="lg">{{ __('No file selected') }}</flux:heading>
                        @endif
                    </div>
                    @if ($current && $this->canManage && ! $allFiles)
                        <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="route('admin.monitoring.logs.download-file', $current->id)">{{ __('Download') }}</flux:button>
                    @endif
                </div>

                <div class="flex flex-wrap items-end gap-3">
                    <div class="min-w-56 flex-1">
                        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="$regex ? __('Regular expression, e.g. timeout|refused') : __('Search the log text')" clearable />
                    </div>
                    <flux:button size="sm" :variant="$regex ? 'primary' : 'outline'" wire:click="$toggle('regex')" :tooltip="__('Treat the search as a regular expression')">.*</flux:button>
                    <flux:button size="sm" :variant="$allFiles ? 'primary' : 'outline'" icon="document-duplicate" wire:click="$toggle('allFiles')">{{ __('All files') }}</flux:button>
                    <div class="w-40"><x-dropdown-select wire:model.live="sort" :options="$sortOptions" /></div>
                    <div class="w-36"><x-dropdown-select wire:model.live="perPage" :options="$perPageOptions" /></div>
                </div>

                <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Filter by level') }}">
                    <flux:button size="xs" :variant="$selectedLevels === [] ? 'primary' : 'outline'" wire:click="clearLevels">
                        {{ __('All levels') }} @unless ($allFiles)<span class="ms-1 opacity-70">{{ array_sum($counts) }}</span>@endunless
                    </flux:button>
                    @foreach (LogLevel::cases() as $level)
                        @php($count = $counts[$level->value] ?? 0)
                        @if ($allFiles || $count > 0 || in_array($level->value, $selectedLevels, true))
                            <flux:button size="xs" :variant="in_array($level->value, $selectedLevels, true) ? 'primary' : 'outline'" wire:click="toggleLevel('{{ $level->value }}')" wire:key="level-{{ $level->value }}">
                                <span @class(['me-1 inline-block size-2 rounded-full', 'bg-red-500' => $level->color() === 'red', 'bg-yellow-500' => $level->color() === 'yellow', 'bg-blue-500' => $level->color() === 'blue', 'bg-green-500' => $level->color() === 'green', 'bg-zinc-400' => $level->color() === 'zinc'])></span>
                                {{ $level->label() }} @unless ($allFiles)<span class="ms-1 opacity-70">{{ number_format($count) }}</span>@endunless
                            </flux:button>
                        @endif
                    @endforeach
                </div>

                @if ($results['error'])
                    <flux:callout variant="danger" icon="exclamation-triangle" :heading="$results['error']" />
                @endif
            </flux:card>

            @if ($this->canManage && $selectedEntries !== [])
                <div class="flex flex-wrap items-center gap-3 rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-2 dark:border-zinc-700 dark:bg-white/5">
                    <flux:text class="text-sm">{{ trans_choice(':count entry selected|:count entries selected', count($selectedEntries)) }}</flux:text>
                    <flux:button size="sm" variant="danger" icon="trash" wire:click="confirmDeleteEntries" data-test="delete-selected-entries">{{ __('Delete selected entries') }}</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="$set('selectedEntries', [])">{{ __('Clear selection') }}</flux:button>
                </div>
            @endif

            <flux:card class="p-0">
                <flux:table>
                    <flux:table.columns>
                        @if ($this->canManage)
                            <flux:table.column class="w-8 ps-4">
                                <flux:checkbox wire:click="toggleEntriesOnPage" :checked="$paginator->count() > 0 && collect($paginator->items())->every(fn ($e) => in_array($e->fileId.':'.$e->offset, $selectedEntries, true))" :aria-label="__('Select all on this page')" />
                            </flux:table.column>
                        @endif
                        <flux:table.column @class(['ps-4' => ! $this->canManage])>{{ __('Level') }}</flux:table.column>
                        <flux:table.column>{{ __('Time') }}</flux:table.column>
                        @if ($allFiles)
                            <flux:table.column>{{ __('File') }}</flux:table.column>
                        @endif
                        <flux:table.column>{{ __('Message') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($paginator as $logEntry)
                            <flux:table.row wire:key="entry-{{ $logEntry->fileId }}-{{ $logEntry->offset }}" class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-white/5" wire:click="openEntry('{{ $logEntry->fileId }}', {{ $logEntry->offset }})" data-test="log-entry">
                                @if ($this->canManage)
                                    <flux:table.cell class="ps-4 align-top">
                                        <flux:checkbox wire:click.stop="toggleEntry('{{ $logEntry->fileId }}:{{ $logEntry->offset }}')" :checked="in_array($logEntry->fileId.':'.$logEntry->offset, $selectedEntries, true)" :aria-label="__('Select entry')" />
                                    </flux:table.cell>
                                @endif
                                <flux:table.cell @class(['align-top', 'ps-4' => ! $this->canManage])><flux:badge size="sm" :color="$logEntry->level->color()">{{ $logEntry->level->label() }}</flux:badge></flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap align-top text-sm">
                                    @if ($logEntry->time)
                                        <span title="{{ $logEntry->time->toDateTimeString() }} UTC">{{ $logEntry->time->timezone($tz)->format('d M Y, H:i:s') }}</span>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </flux:table.cell>
                                @if ($allFiles)
                                    <flux:table.cell class="max-w-40 truncate align-top text-xs text-zinc-500">{{ $logEntry->fileName }}</flux:table.cell>
                                @endif
                                <flux:table.cell class="max-w-0 w-full align-top">
                                    <div class="truncate font-mono text-xs text-zinc-700 dark:text-zinc-200">{{ $logEntry->preview !== '' ? $logEntry->preview : '—' }}</div>
                                    @if ($logEntry->hasMoreLines)
                                        <div class="text-xs text-zinc-400">{{ __('More lines — open to see the full entry') }}</div>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell :colspan="($allFiles ? 4 : 3) + ($this->canManage ? 1 : 0)" class="ps-4">
                                    {{ $search !== '' || $selectedLevels !== [] ? __('No entries match.') : __('This file has no entries.') }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:text class="text-sm">{{ trans_choice(':count entry|:count entries', $paginator->total(), ['count' => number_format($paginator->total())]) }}</flux:text>
                {{ $paginator->links() }}
            </div>
        </div>
    </div>

    {{-- ── Entry details ─────────────────────────────────────────── --}}
    <flux:modal wire:model.self="showEntryModal" class="w-full md:w-[56rem]">
        @if ($opened = $this->opened)
            @php($openedEntry = $opened['entry'])
            <div class="flex flex-col gap-5" x-data="{ copied: null, copy(text, what) { navigator.clipboard.writeText(text); this.copied = what; setTimeout(() => this.copied = null, 1500) } }">
                <div class="flex flex-wrap items-start justify-between gap-3 pe-8">
                    <div class="flex flex-col gap-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:badge :color="$openedEntry->level->color()">{{ $openedEntry->level->label() }}</flux:badge>
                            @if ($openedEntry->environment)
                                <flux:badge size="sm" color="zinc">{{ $openedEntry->environment }}</flux:badge>
                            @endif
                            <flux:text class="text-sm">{{ $openedEntry->fileName }}</flux:text>
                        </div>
                        @if ($openedEntry->time)
                            <flux:text class="text-sm">
                                {{ $openedEntry->time->timezone($tz)->format('l, d M Y · H:i:s') }} {{ __('(Bangladesh)') }}
                                <span class="text-zinc-400">· {{ $openedEntry->time->toDateTimeString() }} UTC</span>
                            </flux:text>
                        @endif
                    </div>
                    <div class="flex gap-2">
                        <flux:button size="sm" icon="clipboard-document" x-on:click="copy($refs.raw.innerText, 'entry')">
                            <span x-text="copied === 'entry' ? $el.dataset.done : $el.dataset.idle" data-idle="{{ __('Copy') }}" data-done="{{ __('Copied') }}">{{ __('Copy') }}</span>
                        </flux:button>
                        @if ($this->canManage)
                            <flux:button size="sm" variant="danger" icon="trash" wire:click="confirmDeleteEntries('{{ $openedEntry->fileId }}:{{ $openedEntry->offset }}')">{{ __('Delete entry') }}</flux:button>
                        @endif
                        <flux:button size="sm" icon="link" data-link="{{ route('admin.monitoring.logs', ['file' => $openedEntry->fileId, 'entry' => $openedEntry->offset]) }}" x-on:click="copy($el.dataset.link, 'link')">
                            <span x-text="copied === 'link' ? $el.dataset.done : $el.dataset.idle" data-idle="{{ __('Copy link') }}" data-done="{{ __('Link copied') }}">{{ __('Copy link') }}</span>
                        </flux:button>
                    </div>
                </div>

                <div>
                    <flux:heading size="sm" class="mb-1">{{ __('Message') }}</flux:heading>
                    <pre class="max-h-60 overflow-auto rounded-lg bg-zinc-50 p-3 text-xs whitespace-pre-wrap text-zinc-800 dark:bg-zinc-800/60 dark:text-zinc-100">{{ $opened['mail'] ? __('An email written to the log (see the preview below).') : $opened['message'] }}</pre>
                </div>

                @if ($opened['exception'])
                    <flux:callout variant="danger" icon="bug-ant" :heading="__('Exception')">
                        <flux:callout.text class="font-mono text-xs break-words">{{ $opened['exception'] }}</flux:callout.text>
                    </flux:callout>
                @endif

                @if ($opened['mail'])
                    @php($mail = $opened['mail'])
                    <div class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:heading size="sm">{{ __('Email preview') }}</flux:heading>
                        <dl class="grid grid-cols-[5rem_1fr] gap-x-3 gap-y-1 text-sm">
                            <dt class="text-zinc-500">{{ __('Subject') }}</dt><dd class="font-medium">{{ $mail['subject'] }}</dd>
                            <dt class="text-zinc-500">{{ __('From') }}</dt><dd>{{ $mail['from'] }}</dd>
                            <dt class="text-zinc-500">{{ __('To') }}</dt><dd>{{ $mail['to'] }}</dd>
                            @if ($mail['date'])
                                <dt class="text-zinc-500">{{ __('Date') }}</dt><dd>{{ $mail['date'] }}</dd>
                            @endif
                        </dl>
                        @if ($mail['html'])
                            {{-- sandbox="": no scripts, no forms, no navigation — just the rendered email. --}}
                            <iframe sandbox="" srcdoc="{{ $mail['html'] }}" class="h-96 w-full rounded-md border border-zinc-200 bg-white dark:border-zinc-700" title="{{ __('Email preview') }}"></iframe>
                        @elseif ($mail['text'])
                            <pre class="max-h-96 overflow-auto rounded-md bg-zinc-50 p-3 text-xs whitespace-pre-wrap dark:bg-zinc-800/60">{{ $mail['text'] }}</pre>
                        @endif
                    </div>
                @endif

                @if ($opened['trace'] !== [])
                    <div>
                        <flux:heading size="sm" class="mb-1">{{ __('Stack trace') }}</flux:heading>
                        <div class="max-h-80 overflow-auto rounded-lg bg-zinc-900 p-3 font-mono text-xs leading-relaxed text-zinc-100">
                            @foreach ($opened['trace'] as $line)
                                <div @class(['whitespace-pre-wrap break-all', 'text-amber-300' => str_contains($line, base_path('app')) || str_contains($line, base_path('routes'))])>{{ $line }}</div>
                            @endforeach
                        </div>
                        <flux:text class="mt-1 text-xs">{{ __('Lines in your own code are highlighted.') }}</flux:text>
                    </div>
                @endif

                @if ($opened['context'])
                    <div>
                        <flux:heading size="sm" class="mb-1">{{ __('Extra data') }}</flux:heading>
                        <pre class="max-h-60 overflow-auto rounded-lg bg-zinc-50 p-3 text-xs dark:bg-zinc-800/60">{{ $opened['context'] }}</pre>
                    </div>
                @endif

                <details class="text-sm">
                    <summary class="cursor-pointer text-zinc-500">{{ __('Full entry as written in the file') }}</summary>
                    <pre x-ref="raw" class="mt-2 max-h-96 overflow-auto rounded-lg bg-zinc-900 p-3 text-xs whitespace-pre-wrap text-zinc-100">{{ $opened['text'] }}</pre>
                    @if ($opened['truncated'])
                        <flux:text class="mt-1 text-xs">{{ __('This entry is very large; only the first 2 MB is shown. Download the file to see all of it.') }}</flux:text>
                    @endif
                </details>
            </div>
        @endif
    </flux:modal>

    {{-- Emptying keeps the .log files; it clears their content. Needs the password. --}}
    <flux:modal wire:model.self="showEmptyModal" class="md:w-[30rem]">
        <form wire:submit="emptyConfirmed" class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ trans_choice('Empty :count log file?|Empty :count log files?', count($this->pendingEmpty)) }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Everything written in them is permanently removed. The files themselves stay and keep receiving new log lines. This is recorded in the Activity log.') }}
                </flux:text>
            </div>

            @if ($this->pendingEmpty !== [])
                <div class="max-h-40 overflow-auto rounded-lg bg-zinc-50 p-3 text-xs text-zinc-600 dark:bg-zinc-800/60 dark:text-zinc-300">
                    @foreach ($this->pendingEmpty as $pending)
                        <div>{{ $pending->relativePath }} <span class="text-zinc-400">· {{ $size($pending->size) }}</span></div>
                    @endforeach
                </div>
            @endif

            <flux:input type="password" wire:model="password" :label="__('Your password')" autocomplete="current-password" viewable />

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showEmptyModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="emptyConfirmed">{{ __('Empty') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-confirm-modal
        wire:model.self="showDeleteEntriesModal"
        :title="trans_choice('Delete :count log entry?|Delete :count log entries?', count($confirmingEntries))"
        :description="__('They are permanently removed from the log file; everything else in the file stays. This is recorded in the Activity log.')"
        confirm-action="deleteEntriesConfirmed"
        :confirm-label="__('Delete')"
    />
</div>
