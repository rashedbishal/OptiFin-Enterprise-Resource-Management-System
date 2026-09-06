<div class="relative" x-data x-on:click.outside="$wire.close()">
    <button
        type="button"
        wire:click="toggle"
        :aria-expanded="@js($open)"
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

    @if ($open)
        <div
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
            x-transition:enter-end="opacity-100 scale-100 translate-y-0"
            class="absolute right-0 z-50 mt-2 w-80 origin-top-right rounded-xl border border-zinc-200/70 bg-white/95 shadow-xl backdrop-blur-sm sm:w-96 dark:border-zinc-700/70 dark:bg-zinc-900/95"
        >
            <div class="flex items-center justify-between gap-3 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <flux:heading size="sm">{{ __('Notifications') }}</flux:heading>
                <div class="flex items-center gap-3">
                    @if ($this->unreadCount > 0)
                        <button type="button" wire:click.renderless="markAllAsRead" class="cursor-pointer text-xs font-medium text-accent hover:underline">
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
        </div>
    @endif

    <x-confirm-modal
        wire:model.self="showClearAllModal"
        :title="__('Clear all notifications?')"
        :description="__('This removes every notification in this list — read and unread. This can\'t be undone.')"
        confirm-action="clearAllConfirmed"
        :confirm-label="__('Clear All')"
    />
</div>
