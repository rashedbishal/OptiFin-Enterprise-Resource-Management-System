@php
    $compact ??= false;
    $fillHeight ??= false;

    // Self-contained (not passed in like $activity) since both callers
    // (show.blade.php, show-panel.blade.php) need a pair here, but NOT
    // the identical one any more: show.blade.php's Activity tab needs to
    // re-shimmer every time it's switched to (see
    // Show::$tabContentReady's docblock), but show-panel.blade.php's
    // flyout has no tabs at all — $tabContentReady never gets set there
    // (switchTab()/markTabContentReady() are never called), so gating on
    // it unconditionally would leave the flyout's Activity card stuck on
    // its skeleton forever. $panel (true only for the flyout) picks the
    // right pair for whichever context this renders in. Both
    // $panel/$initialLoadComplete/$tabContentReady are public Livewire
    // properties, always in scope here via Blade's @include variable
    // inheritance, no explicit pass-through needed. See leads.blade.php's
    // identical $showSkeletonByDefault for why the skeleton's/real
    // content's STATIC class (not just their wire:loading toggle) has to
    // be conditional on server-known state.
    $listLoadingTargets = $panel ? 'completeInitialLoad' : 'switchTab,markTabContentReady';
    $showSkeletonByDefault = $panel ? ! $initialLoadComplete : ! $tabContentReady;
@endphp

<flux:card class="{{ $fillHeight ? 'flex flex-col md:h-full' : '' }}">
    <flux:heading size="lg" class="mb-4">{{ __('Activity') }}</flux:heading>
    <div class="flex flex-col gap-4 overflow-y-auto pe-1 {{ $fillHeight ? 'max-h-96 md:max-h-none md:min-h-0 md:flex-1' : ($compact ? 'max-h-48' : 'max-h-96') }}">
        {{-- Real content: hidden by default before initialLoadComplete
        (see $showSkeletonByDefault), toggled by wire:loading otherwise —
        never a bare wire:loading/.remove, see leads.blade.php's long
        comment for why. --}}
        <div wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" class="{{ $showSkeletonByDefault ? '!hidden' : '' }} contents">
        @forelse ($activity as $event)
            <div class="border-b border-zinc-200 pb-3 last:border-0 dark:border-zinc-700">
                {{-- Actor leads the line (bold, full color) rather than
                trailing after the timestamp in muted gray — "who did this"
                is the more important half of this line, not "when". --}}
                <flux:text class="text-xs">
                    <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ $event['user'] ?? __('System') }}</span>
                    <span class="text-zinc-500">· {{ $event['created_at']?->diffForHumans() }}</span>
                </flux:text>
                @if ($event['type'] === 'status_change')
                    <flux:text>{{ __('Status changed to :status', ['status' => ucfirst(str_replace('_', ' ', $event['new_status']))]) }}</flux:text>
                @elseif ($event['type'] === 'assignment')
                    <flux:text>{{ __('Assigned to :name', ['name' => $event['assigned_to']]) }}</flux:text>
                @elseif ($event['type'] === 'unassigned')
                    <flux:text>{{ __('Unassigned') }}</flux:text>
                @elseif ($event['type'] === 'details_updated')
                    <flux:text>{{ __('Updated: :fields', ['fields' => implode(', ', $event['fields'])]) }}</flux:text>
                @elseif ($event['type'] === 'meeting_cancelled')
                    <flux:text>{{ __('Meeting scheduled for :date was cancelled', ['date' => $event['action_date']->format('l, M j, Y')]) }}</flux:text>
                @elseif ($event['type'] === 'meeting_done')
                    <flux:text>{{ __('Meeting scheduled for :date was marked as done', ['date' => $event['action_date']->format('l, M j, Y')]) }}</flux:text>
                @elseif ($event['type'] === 'followup_cancelled')
                    <flux:text>{{ __('Follow-up scheduled for :date was cancelled', ['date' => $event['action_date']->format('l, M j, Y')]) }}</flux:text>
                @elseif ($event['type'] === 'followup_done')
                    <flux:text>{{ __('Follow-up scheduled for :date was marked as done', ['date' => $event['action_date']->format('l, M j, Y')]) }}</flux:text>
                @elseif ($event['type'] === 'lead_merged')
                    <flux:text>{{ __('Merged with lead :identifier', ['identifier' => $event['other_lead_identifier']]) }}</flux:text>
                @elseif ($event['type'] === 'tag_added')
                    <flux:text>{{ __('Tag added: :tag', ['tag' => $event['fields']['tag'] ?? '']) }}</flux:text>
                @elseif ($event['type'] === 'tag_removed')
                    <flux:text>{{ __('Tag removed: :tag', ['tag' => $event['fields']['tag'] ?? '']) }}</flux:text>
                @elseif ($event['type'] === 'deal_status_change')
                    <flux:text>{{ __('Deal ":deal" status changed to :status', ['deal' => $event['deal_name'], 'status' => $event['new_status']]) }}</flux:text>
                @elseif ($event['type'] === 'task_status_change')
                    <flux:text>{{ __('Task ":task" status changed to :status', ['task' => $event['task_title'], 'status' => $event['new_status']]) }}</flux:text>
                @elseif ($event['type'] === 'consent_change')
                    <flux:text>
                        {{ __(':type :status', ['type' => $event['consent_type']->label(), 'status' => strtolower($event['consent_status']->label())]) }}
                        <span class="text-zinc-500">
                            ({{ $event['consent_source']->value === 'website_form' ? __('Website Form: :name', ['name' => $event['website_form_name'] ?? __('unknown')]) : $event['consent_source']->label() }})
                        </span>
                    </flux:text>
                @else
                    <flux:text>{{ $event['note'] }}</flux:text>
                @endif
                @if (! empty($event['note']) && $event['type'] !== 'note')
                    <flux:text class="text-xs italic">{{ $event['note'] }}</flux:text>
                @endif
            </div>
        @empty
            <flux:text>{{ __('No activity yet.') }}</flux:text>
        @endforelse
        </div>

        {{-- Skeleton: hidden by default, revealed only while
        initialLoadComplete's request is in flight — see leads.blade.php
        for why this has to be class-based, never a bare wire:loading. Item
        count is arbitrary (4), this feed is never paginated. --}}
        <div class="{{ $showSkeletonByDefault ? '' : '!hidden' }} contents" wire:loading.remove.class="!hidden" wire:target="{{ $listLoadingTargets }}">
            @for ($i = 0; $i < 4; $i++)
                <div wire:key="activity-skeleton-{{ $i }}" class="border-b border-zinc-200 pb-3 last:border-0 dark:border-zinc-700">
                    <div class="mb-1.5 flex items-center gap-2">
                        <div class="h-2.5 w-16 skeleton-shimmer rounded"></div>
                        <div class="h-2.5 w-12 skeleton-shimmer rounded"></div>
                    </div>
                    <div class="h-3 w-40 skeleton-shimmer rounded"></div>
                </div>
            @endfor
        </div>
    </div>
</flux:card>
