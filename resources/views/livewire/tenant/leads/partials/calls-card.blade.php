@php
    // Rendered as part of App\Livewire\Tenant\Leads\Show's own view tree
    // (included from both show.blade.php and show-panel.blade.php), so $this
    // is the Show component instance — the same "call $this->x directly in a
    // shared partial" pattern consent-card.blade.php uses.
    //
    // Shown for a WhatsApp lead (so the empty state explains where calls will
    // appear) or for any lead that already has calls.
    $calls = $this->leadCalls;
    $showCalls = $calls->isNotEmpty() || $lead->source?->type === \App\Enums\LeadSourceType::Whatsapp;
@endphp

@if ($showCalls)
    <flux:card class="flex flex-col gap-4" data-test="lead-calls-card">
        <div class="flex items-center justify-between gap-2">
            <flux:heading size="lg">{{ __('Calls') }}</flux:heading>
            <flux:badge size="sm" icon="phone-arrow-down-left" color="zinc">{{ __('Incoming') }}</flux:badge>
        </div>

        @forelse ($calls as $call)
            @php
                $duration = \App\Support\LeadCallPresenter::duration($call->duration_seconds);
                $reason = \App\Support\LeadCallPresenter::endReason($call->end_reason);
            @endphp

            <div class="flex flex-col gap-1.5 {{ $loop->first ? '' : 'border-t border-zinc-200 pt-3 dark:border-zinc-700' }}" wire:key="lead-call-{{ $call->id }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:badge size="sm" :color="\App\Support\LeadCallPresenter::statusColor($call->status)">{{ $call->status->label() }}</flux:badge>
                    <flux:text size="sm" variant="subtle" title="{{ $call->created_at->toDayDateTimeString() }}">{{ $call->created_at->diffForHumans() }}</flux:text>
                </div>

                <div class="flex flex-wrap gap-x-4 gap-y-1">
                    @if ($call->connected_at)
                        <flux:text size="sm">{{ __('Connected') }}: {{ $call->connected_at->format('H:i:s') }}</flux:text>
                    @endif
                    @if ($call->ended_at)
                        <flux:text size="sm">{{ __('Ended') }}: {{ $call->ended_at->format('H:i:s') }}</flux:text>
                    @endif
                    @if ($duration)
                        <flux:text size="sm">{{ __('Duration') }}: {{ $duration }}</flux:text>
                    @endif
                    @if ($call->claimedBy)
                        <flux:text size="sm">{{ __('Answered by') }}: {{ $call->claimedBy->name }}</flux:text>
                    @endif
                </div>

                @if ($reason)
                    <flux:text size="sm" variant="subtle">{{ $reason }}</flux:text>
                @endif
            </div>
        @empty
            <flux:text variant="subtle">{{ __('No calls yet.') }}</flux:text>
        @endforelse
    </flux:card>
@endif
