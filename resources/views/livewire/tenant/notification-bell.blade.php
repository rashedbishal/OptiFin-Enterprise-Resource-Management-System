<div
    class="relative"
    x-data="{ open: false }"
    x-on:click.outside="open = false"
>
    {{-- Clicking flips Alpine's LOCAL `open` instantly (the dropdown shell
    below shows immediately, with a skeleton — see $listLoadingTargets)
    while openNotifications() fires in parallel to actually fetch fresh
    data. This used to be a single server round trip
    ($open toggled server-side, dropdown only existed in the DOM once that
    returned) — see NotificationBell::$notificationsLoaded's docblock for
    why: that made the click feel laggy on a slow connection with nothing
    to show for the wait, and there was no way to show a loading skeleton
    for content that didn't exist in the DOM yet. --}}
    <button
        type="button"
        wire:click="openNotifications"
        x-on:click="open = ! open"
        :aria-expanded="open"
        aria-label="{{ __('Notifications') }}"
        class="relative flex size-9 items-center justify-center rounded-full text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-700 dark:hover:text-zinc-200 cursor-pointer"
    >
        <flux:icon icon="bell" variant="outline" class="size-5" />

        @if ($this->unreadCount > 0)
            <span class="absolute top-1 right-1 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] leading-none font-semibold text-white">
                {{ $this->unreadCount > 99 ? '99+' : $this->unreadCount }}
            </span>
        @endif
    </button>

    @php
        // Every action that re-queries $this->notifications: just
        // openNotifications() — this dropdown has no filters. wire:loading
        // pairs below watch this so the skeleton only appears for the
        // fetch this button itself triggers, not unrelated requests
        // (markAsRead, clearAll, ...) that happen to run while it's open.
        $listLoadingTargets = 'openNotifications';
    @endphp

    {{-- Always in the DOM (not @if-gated server-side any more — see above)
    so Alpine's x-show can reveal it instantly on click.

    No static "hidden"/"!hidden" CLASS here — deliberately tried that and
    it broke opening: Alpine's x-show, when paired with x-transition, shows
    an element by clearing its OWN inline `display` back to an empty
    string (falls back to the CSS cascade) rather than forcing a specific
    value, so a competing class-based `display:none` (even `!hidden`'s
    !important version) kept winning regardless of Alpine's state — no
    inline override was ever actively canceling it.

    x-cloak IS here though, for a DIFFERENT problem: without it, this div
    has literally nothing hiding it in the raw server-rendered HTML —
    x-show's own default-hidden behavior only takes effect once Alpine's
    script has downloaded, parsed, and processed this element, which isn't
    instant. The dropdown was visibly flashing open on every page load
    until this existed. [x-cloak] { display: none !important } (see
    app.css) hides it for that pre-hydration window; Alpine removes the
    x-cloak attribute itself the moment it finishes initializing this
    element, at which point x-show's own inline-style toggling takes over
    exclusively — the two never actually fight each other, x-cloak has
    already stopped applying by the time x-show starts. --}}
    <div
        x-cloak
        x-show="open"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        class="absolute right-0 z-50 mt-2 w-80 origin-top-right rounded-xl border border-zinc-200/70 bg-white shadow-xl backdrop-blur-sm sm:w-96 dark:border-zinc-700/70 dark:bg-zinc-900"
    >
        <div class="flex items-center justify-between gap-3 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('Notifications') }}</flux:heading>
            <div class="flex items-center gap-3">
                @if ($this->unreadCount > 0)
                    <button type="button" wire:click="markAllAsRead" class="cursor-pointer text-xs font-medium text-accent hover:underline">
                        {{ __('Mark all as read') }}
                    </button>
                @endif
                @if (count($this->notifications) > 0)
                    <button type="button" wire:click="confirmClearAll" class="cursor-pointer text-xs font-medium text-zinc-400 hover:text-red-500 dark:hover:text-red-400">
                        {{ __('Clear all') }}
                    </button>
                @endif
            </div>
        </div>

        <div class="max-h-96 overflow-y-auto">
            {{-- Real content: hidden by default until notificationsLoaded
            (see $notificationsLoaded's docblock — the dropdown can be
            open, via Alpine, before the server has confirmed any fetch at
            all), toggled by wire:loading otherwise — never a bare
            wire:loading/.remove, see leads.blade.php's long comment for
            why. --}}
            <div wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" class="{{ $this->notificationsLoaded ? '' : '!hidden' }} contents">
                @forelse ($this->notifications as $n)
                    {{-- The lead link is a "stretched" anchor (absolute
                    inset-0) rather than wrapping the title/message text —
                    conditionally opening an <a> vs a <div> around the same
                    content (matched by a second conditional closing tag
                    further down) is easy to get subtly unbalanced; a
                    same-content div with an absolutely-positioned overlay
                    link sidesteps that entirely, and still lets the unread
                    dot below sit above it (z-10) to stay independently
                    clickable. --}}
                    <div wire:key="notification-{{ $n['id'] }}" class="relative flex items-start gap-3 border-b border-zinc-100 px-4 py-3 last:border-b-0 dark:border-zinc-800 {{ $n['read'] ? '' : 'bg-accent/5' }}">
                        <div class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <flux:icon :icon="$n['icon']" variant="micro" class="size-4 text-zinc-500 dark:text-zinc-400" />
                        </div>

                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ $n['title'] }}</p>
                            <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $n['message'] }}</p>
                            <p class="mt-0.5 text-[11px] text-zinc-400 dark:text-zinc-500">{{ $n['created_at']->diffForHumans() }}</p>
                        </div>

                        @if ($n['url'])
                            {{-- Points at NotificationRedirectController, which marks
                            this read and redirects server-side in one request — see
                            NotificationBell::present() for why this isn't a plain
                            wire:click.renderless="markAsRead(...)" alongside
                            wire:navigate any more. --}}
                            <a
                                href="{{ $n['url'] }}"
                                wire:navigate
                                class="absolute inset-0"
                                aria-label="{{ $n['title'] }}"
                            ></a>
                        @endif

                        @unless ($n['read'])
                            <button
                                type="button"
                                wire:click.stop.prevent="markAsRead('{{ $n['id'] }}')"
                                class="relative z-10 mt-1.5 size-2.5 shrink-0 cursor-pointer rounded-full bg-accent"
                                aria-label="{{ __('Mark as read') }}"
                            ></button>
                        @endunless
                    </div>
                @empty
                    <div class="flex flex-col items-center gap-2 px-4 py-10 text-center">
                        <flux:icon icon="bell" class="size-6 text-zinc-300 dark:text-zinc-600" />
                        <flux:text size="sm" variant="subtle">{{ __('No notifications yet.') }}</flux:text>
                    </div>
                @endforelse
            </div>

            {{-- Skeleton: shown until notificationsLoaded, i.e. from the
            moment the dropdown is opened (Alpine, instant) until
            openNotifications()'s round trip actually lands — see
            leads.blade.php for why this can't be a bare wire:loading
            directive. Row count is arbitrary (4), this list is capped at
            RECENT_LIMIT but never paginated. --}}
            <div class="{{ $this->notificationsLoaded ? '!hidden' : '' }} contents" wire:loading.remove.class="!hidden" wire:target="{{ $listLoadingTargets }}">
                @for ($i = 0; $i < 4; $i++)
                    <div wire:key="notification-skeleton-{{ $i }}" class="flex items-start gap-3 border-b border-zinc-100 px-4 py-3 last:border-b-0 dark:border-zinc-800">
                        <div class="mt-0.5 size-8 shrink-0 skeleton-shimmer rounded-full"></div>
                        <div class="min-w-0 flex-1 flex flex-col gap-1.5">
                            <div class="h-3 w-32 skeleton-shimmer rounded"></div>
                            <div class="h-2.5 w-full skeleton-shimmer rounded"></div>
                            <div class="h-2.5 w-16 skeleton-shimmer rounded"></div>
                        </div>
                    </div>
                @endfor
            </div>
        </div>
    </div>

    <x-confirm-modal
        wire:model.self="showClearAllModal"
        :title="__('Clear all notifications?')"
        :description="__('This removes every notification in this list — read and unread. This can\'t be undone.')"
        confirm-action="clearAllConfirmed"
        :confirm-label="__('Clear All')"
    />
</div>
