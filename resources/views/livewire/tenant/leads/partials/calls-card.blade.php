@php
    // Rendered as part of App\Livewire\Tenant\Leads\Show's own view tree
    // (included from both show.blade.php and show-panel.blade.php), so $this
    // is the Show component instance — the same "call $this->x directly in a
    // shared partial" pattern consent-card.blade.php uses.
    //
    // Shown for a WhatsApp lead (so the empty state explains where calls will
    // appear) or for any lead that already has calls.
    $scrollable ??= false;
    $calls = $this->leadCalls;
    $showCalls = $calls->isNotEmpty() || $lead->source?->type === \App\Enums\LeadSourceType::Whatsapp;
@endphp

@if ($showCalls)
    <flux:card @class([
        'flex flex-col gap-4',
        'min-h-96 overflow-hidden border border-zinc-200/80 shadow-md ring-1 ring-zinc-950/5 dark:border-white/10 dark:ring-white/5' => $scrollable,
    ]) data-test="lead-calls-card">
        @if ($scrollable)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200/70 pb-3 dark:border-zinc-700/70">
                <div class="flex min-w-0 items-center gap-3">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl border border-indigo-200/80 bg-indigo-50 text-indigo-600 shadow-2xs dark:border-indigo-800/70 dark:bg-indigo-950/60 dark:text-indigo-300">
                        <flux:icon.phone-arrow-down-left variant="micro" class="size-5" />
                    </div>
                    <div class="min-w-0">
                        <flux:heading size="lg" class="font-semibold tracking-tight">{{ __('Calls') }}</flux:heading>
                        <flux:text size="xs" variant="subtle">{{ __('Recent call activity') }}</flux:text>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <flux:badge size="sm" variant="pill" color="indigo">{{ $calls->count() }}</flux:badge>
                    <flux:badge size="sm" icon="phone-arrow-down-left" color="zinc">{{ __('Incoming') }}</flux:badge>
                </div>
            </div>
        @else
            <div class="flex items-center justify-between gap-2">
                <flux:heading size="lg">{{ __('Calls') }}</flux:heading>
                <flux:badge size="sm" icon="phone-arrow-down-left" color="zinc">{{ __('Incoming') }}</flux:badge>
            </div>
        @endif

        <div @class([
            'flex flex-col',
            'gap-4' => ! $scrollable,
            'min-h-0 flex-1 gap-3 overflow-y-auto overscroll-contain pe-1' => $scrollable,
        ])>
        @forelse ($calls as $call)
            @php
                $duration = \App\Support\LeadCallPresenter::duration($call->duration_seconds);
                $reason = \App\Support\LeadCallPresenter::endReason($call->end_reason);
            @endphp

            <div @class([
                'flex flex-col gap-1.5' => ! $scrollable,
                'group flex flex-col gap-2.5 rounded-xl border border-zinc-200/80 bg-white/75 p-3.5 shadow-2xs transition duration-700 ease-[var(--motion-ease-premium)] hover:border-indigo-300/80 hover:bg-indigo-50/30 dark:border-zinc-700/80 dark:bg-zinc-900/60 dark:hover:border-indigo-700/70 dark:hover:bg-indigo-950/20' => $scrollable,
                'border-t border-zinc-200 pt-3 dark:border-zinc-700' => ! $scrollable && ! $loop->first,
            ]) wire:key="lead-call-{{ $call->id }}">
                <div @class([
                    'flex flex-wrap items-center justify-between gap-2',
                    'border-b border-zinc-200/70 pb-2.5 dark:border-zinc-700/70' => $scrollable,
                ])>
                    <flux:badge size="sm" :color="\App\Support\LeadCallPresenter::statusColor($call->status)">{{ $call->status->label() }}</flux:badge>
                    <flux:text size="sm" variant="subtle" class="{{ $scrollable ? 'font-medium text-zinc-500 dark:text-zinc-400' : '' }}" title="{{ $call->created_at->toDayDateTimeString() }}">{{ $call->created_at->diffForHumans() }}</flux:text>
                </div>

                <div @class([
                    'flex flex-wrap gap-x-4 gap-y-1' => ! $scrollable,
                    'flex flex-wrap gap-2' => $scrollable,
                ])>
                    @if ($call->connected_at)
                        <flux:text size="sm" class="{{ $scrollable ? 'rounded-lg border border-zinc-200/70 bg-zinc-50/80 px-2.5 py-1.5 font-medium text-zinc-700 dark:border-zinc-700/60 dark:bg-zinc-800/50 dark:text-zinc-300' : '' }}">{{ __('Connected') }}: {{ $call->connected_at->format('H:i:s') }}</flux:text>
                    @endif
                    @if ($call->ended_at)
                        <flux:text size="sm" class="{{ $scrollable ? 'rounded-lg border border-zinc-200/70 bg-zinc-50/80 px-2.5 py-1.5 font-medium text-zinc-700 dark:border-zinc-700/60 dark:bg-zinc-800/50 dark:text-zinc-300' : '' }}">{{ __('Ended') }}: {{ $call->ended_at->format('H:i:s') }}</flux:text>
                    @endif
                    @if ($duration)
                        <flux:text size="sm" class="{{ $scrollable ? 'rounded-lg border border-zinc-200/70 bg-zinc-50/80 px-2.5 py-1.5 font-medium text-zinc-700 dark:border-zinc-700/60 dark:bg-zinc-800/50 dark:text-zinc-300' : '' }}">{{ __('Duration') }}: {{ $duration }}</flux:text>
                    @endif
                    @if ($call->claimedBy)
                        <flux:text size="sm" class="{{ $scrollable ? 'rounded-lg border border-zinc-200/70 bg-zinc-50/80 px-2.5 py-1.5 font-medium text-zinc-700 dark:border-zinc-700/60 dark:bg-zinc-800/50 dark:text-zinc-300' : '' }}">{{ __('Answered by') }}: {{ $call->claimedBy->name }}</flux:text>
                    @endif
                </div>

                @if ($reason)
                    <flux:text size="sm" variant="subtle" class="{{ $scrollable ? 'rounded-lg border border-zinc-200/70 bg-zinc-50/70 px-2.5 py-2 dark:border-zinc-700/60 dark:bg-zinc-800/40' : '' }}">{{ $reason }}</flux:text>
                @endif
            </div>
        @empty
            @if ($scrollable)
                <div class="flex h-full min-h-48 flex-col items-center justify-center gap-3 text-center">
                    <div class="flex size-12 items-center justify-center rounded-2xl border border-zinc-200/80 bg-zinc-50 text-zinc-400 shadow-2xs dark:border-zinc-700 dark:bg-zinc-800/70 dark:text-zinc-500">
                        <flux:icon.phone-arrow-down-left variant="micro" class="size-5" />
                    </div>
                    <div>
                        <flux:text variant="strong">{{ __('No calls yet.') }}</flux:text>
                        <flux:text size="sm" variant="subtle">{{ __('Incoming call records will appear here.') }}</flux:text>
                    </div>
                </div>
            @else
                <flux:text variant="subtle">{{ __('No calls yet.') }}</flux:text>
            @endif
        @endforelse
        </div>
    </flux:card>
@endif
