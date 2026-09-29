@php
    $compact ??= false;
    $premium ??= false;
    $hasConversation = count($lead->messageTimeline()) > 0;

    // Self-contained (like activity-card.blade.php) since this partial is
    // shared by two callers (show.blade.php, show-panel.blade.php) that
    // need DIFFERENT pairs, not the identical one — see
    // activity-card.blade.php's identical $panel branching for why (the
    // flyout has no tabs, so $tabContentReady never gets set there). This
    // is purely a visual reveal-together toggle, not a real query defer —
    // see lead-hero.blade.php's identical pair for why: $lead's fields
    // (including messageTimeline()/attachments below) are already
    // resolved by the time this renders, nothing here is deferred.
    // Deliberately does NOT gate $this->timeline itself (see
    // Show::$initialLoadComplete's docblock for why that would be unsafe:
    // the @if below branches on $lead->messageTimeline() directly, not on
    // $timeline, so gating the latter alone would leave this @if
    // evaluating true while iterating an empty array — a blank box, not a
    // skeleton) — only this card's overall visibility is toggled, all of
    // its existing branching is untouched.
    $listLoadingTargets = $panel ? 'completeInitialLoad' : 'switchTab,markTabContentReady';
    $showSkeletonByDefault = $panel ? ! $initialLoadComplete : ! $tabContentReady;
@endphp

<flux:card class="{{ $premium ? 'overflow-hidden border-zinc-200/80 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-zinc-900' : '' }} {{ $premium && $hasConversation ? 'h-full max-h-[34rem]' : '' }}">
    <div class="{{ $premium ? '-mx-6 -mt-6 mb-5 flex items-center justify-between gap-3 border-b border-zinc-200/80 bg-[linear-gradient(115deg,rgba(255,255,255,0.96)_0%,rgba(248,250,252,0.92)_48%,rgba(239,246,255,0.88)_100%)] px-6 py-4 dark:border-zinc-700/80 dark:bg-[linear-gradient(115deg,rgba(24,24,27,0.98)_0%,rgba(24,24,27,0.94)_48%,rgba(23,37,84,0.76)_100%)]' : '' }}">
        <div class="flex min-w-0 items-center gap-3">
            @if ($premium)
                <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-blue-600 shadow-lg shadow-blue-600/20">
                    <flux:icon icon="chat-bubble-left-right" class="size-5 text-white" />
                </div>
            @endif
            <div>
                <flux:heading size="lg" class="{{ $premium ? 'tracking-tight' : 'mb-4' }}">{{ $premium ? __('Conversation') : __('Details') }}</flux:heading>
                @if ($premium)
                    <flux:text size="sm" variant="subtle">{{ __('Lead communication history') }}</flux:text>
                @endif
            </div>
        </div>

        @if ($premium && $hasConversation)
            <span class="hidden shrink-0 rounded-full border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-500 sm:inline-flex dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">
                {{ trans_choice(':count message|:count messages', count($lead->messageTimeline()), ['count' => count($lead->messageTimeline())]) }}
            </span>
        @endif
    </div>

    {{-- Real content: hidden by default before initialLoadComplete, toggled
    by wire:loading otherwise — never a bare wire:loading/.remove, see
    leads.blade.php's long comment for why. --}}
    <div wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" class="{{ $showSkeletonByDefault ? '!hidden' : '' }} contents">
    <div class="{{ $premium ? 'mb-5 flex items-center gap-3 rounded-2xl border border-white/80 bg-[linear-gradient(135deg,rgba(255,255,255,0.92)_0%,rgba(248,250,252,0.78)_58%,rgba(239,246,255,0.72)_100%)] p-3 shadow-sm shadow-slate-950/5 ring-1 ring-zinc-200/70 dark:border-white/10 dark:bg-[linear-gradient(135deg,rgba(39,39,42,0.86)_0%,rgba(24,24,27,0.78)_58%,rgba(30,41,59,0.72)_100%)] dark:shadow-black/20 dark:ring-white/5' : 'mb-4 flex items-center gap-3' }}">
        <div class="flex size-10 shrink-0 items-center justify-center {{ $premium ? 'rounded-xl bg-white shadow-sm ring-1 ring-zinc-200/80 dark:bg-zinc-800 dark:ring-zinc-700' : 'rounded-full bg-zinc-100 dark:bg-zinc-700' }}">
            <flux:icon :icon="$lead->source->type->icon()" class="size-5 text-zinc-600 dark:text-zinc-300" />
        </div>
        <div>
            <flux:text variant="strong" class="text-zinc-900 dark:text-white font-semibold">{{ $lead->source->name }}</flux:text>
            <span class="text-xs sm:text-sm font-medium text-zinc-700 dark:text-zinc-300 block">
                {{ $lead->source->type->label() }}
                @if ($lead->created_at)
                    &middot; {{ $lead->created_at->diffForHumans() }}
                @endif
            </span>
        </div>
    </div>

    @if ($hasConversation)
        {{-- Webhook-sourced leads (Messenger/Instagram/WhatsApp) can carry
        several text messages and attachments over time — shown here as a
        single feed, in the order they actually arrived, rather than as a
        flattened message blob with attachments clustered separately. Gated
        on messageTimeline() specifically (not just "has any attachment"):
        an email lead can have attachments too, but it isn't a threaded
        conversation and should keep its own body/HTML rendering below. --}}
        <flux:separator class="{{ $premium ? 'mb-5' : 'mb-4' }}" />

        <div
            x-data
            x-init="
                // This lives inside the flyout dialog, which is display:none
                // until opened — scrollHeight reads as 0 at Alpine's init
                // time, before the panel is ever visible, so a plain
                // x-init scroll-to-bottom silently no-ops. IntersectionObserver
                // instead fires each time the element actually becomes
                // visible (i.e. every time the panel opens), which is also
                // exactly when 'always default to the bottom' should apply.
                new IntersectionObserver((entries) => {
                    if (entries[0].isIntersecting) { $el.scrollTop = $el.scrollHeight; }
                }).observe($el);
                new MutationObserver(() => { $el.scrollTop = $el.scrollHeight; }).observe($el, { childList: true, subtree: true });
            "
            class="flex {{ $compact ? 'max-h-48' : 'max-h-96' }} {{ $premium ? 'rounded-2xl border border-white/80 bg-[radial-gradient(105%_82%_at_100%_0%,rgba(219,234,254,0.72)_0%,rgba(219,234,254,0)_62%),radial-gradient(86%_82%_at_0%_100%,rgba(237,233,254,0.52)_0%,rgba(237,233,254,0)_66%),linear-gradient(135deg,rgba(255,255,255,0.92)_0%,rgba(248,250,252,0.84)_52%,rgba(239,246,255,0.88)_100%)] p-3 shadow-inner shadow-slate-950/5 ring-1 ring-zinc-200/70 sm:p-4 dark:border-white/10 dark:bg-[radial-gradient(105%_82%_at_100%_0%,rgba(30,58,138,0.34)_0%,rgba(30,58,138,0)_62%),radial-gradient(86%_82%_at_0%_100%,rgba(76,29,149,0.18)_0%,rgba(76,29,149,0)_66%),linear-gradient(135deg,rgba(9,9,11,0.96)_0%,rgba(24,24,27,0.92)_52%,rgba(15,23,42,0.94)_100%)] dark:shadow-black/35 dark:ring-white/5' : '' }} flex-col gap-4 overflow-y-auto pe-1 contain-paint"
        >
            {{-- Messenger-style feed: what the customer sent (and every
            attachment, which only ever arrives inbound) sits on the left in
            a neutral bubble; what this business sent sits on the right in a
            blue bubble, with its delivery status under it. Presentation
            only — the order, the data and the scroll behavior above are
            unchanged. --}}
            @php $previousMessageDate = null; @endphp
            @foreach ($timeline as $item)
                @php
                    $outgoing = $item['type'] !== 'attachment' && $item['direction'] === \App\Enums\MessageDirection::Outbound;
                    $sentAt = $item['at'] ?? null;
                @endphp

                @if ($premium && $sentAt && (! $previousMessageDate || ! $sentAt->isSameDay($previousMessageDate)))
                    <div class="flex items-center gap-3 py-1">
                        <span class="h-px flex-1 bg-zinc-200/80 dark:bg-zinc-700/80"></span>
                        <span class="rounded-full border border-zinc-200/80 bg-white/80 px-2.5 py-1 text-[10px] font-semibold tracking-wide text-zinc-500 uppercase dark:border-zinc-700/80 dark:bg-zinc-800/80 dark:text-zinc-400">
                            @if ($sentAt->isToday())
                                {{ __('Today') }}
                            @elseif ($sentAt->isYesterday())
                                {{ __('Yesterday') }}
                            @else
                                {{ $sentAt->format('M j, Y') }}
                            @endif
                        </span>
                        <span class="h-px flex-1 bg-zinc-200/80 dark:bg-zinc-700/80"></span>
                    </div>
                @endif
                @php $previousMessageDate = $sentAt ?? $previousMessageDate; @endphp

                <div class="flex w-full items-end gap-2.5 {{ $outgoing ? 'justify-end' : 'justify-start' }}">
                    @if ($premium && ! $outgoing)
                        <div class="mb-5 flex size-8 shrink-0 items-center justify-center rounded-xl border border-white bg-white text-zinc-500 shadow-sm ring-1 ring-zinc-200/70 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-700">
                            <flux:icon :icon="$lead->source->type->icon()" class="size-4" />
                        </div>
                    @endif

                <div class="flex max-w-[82%] flex-col gap-1 {{ $outgoing ? 'items-end' : 'items-start' }}">
                    @if ($premium)
                        <span class="px-1 text-[10px] font-bold tracking-wide text-zinc-400 uppercase dark:text-zinc-500">{{ $outgoing ? __('Your team') : $lead->source->name }}</span>
                    @endif
                    @if ($item['type'] === 'text')
                        <div @class([
                            'w-fit min-w-14 rounded-2xl px-3.5 py-2.5 text-sm leading-6 break-words whitespace-pre-line shadow-sm',
                            'rounded-br-md bg-linear-to-br from-blue-600 to-indigo-600 text-white shadow-blue-600/20' => $outgoing && $premium,
                            'rounded-br-md bg-blue-600 text-white' => $outgoing && ! $premium,
                            'rounded-bl-md border border-zinc-200/80 bg-white text-zinc-900 shadow-zinc-950/5 dark:border-zinc-700/80 dark:bg-zinc-800 dark:text-zinc-100' => ! $outgoing && $premium,
                            'rounded-bl-md bg-zinc-100 text-zinc-900 dark:bg-zinc-700 dark:text-zinc-100' => ! $outgoing && ! $premium,
                        ])><span class="sr-only">{{ $outgoing ? __('You') : __('Customer') }}:</span>{{ $item['text'] }}</div>
                    @elseif ($item['type'] === 'media')
                        @php
                            $mediaUrl = route('tenant-leads.message-attachment', [$lead, $item['media']['message_id']]);
                        @endphp
                        <div class="rounded-2xl rounded-br-md {{ $premium ? 'bg-linear-to-br from-blue-600 to-indigo-600 shadow-sm shadow-blue-600/20' : 'bg-blue-600' }} p-1.5 text-sm break-words text-white">
                            <span class="sr-only">{{ __('You') }}:</span>
                            @if ($item['media']['kind'] === 'image')
                                <a href="{{ $mediaUrl }}" target="_blank" rel="noopener">
                                    <img src="{{ $mediaUrl }}" alt="{{ $item['media']['name'] }}" loading="lazy" class="max-h-64 w-auto max-w-full rounded-xl object-cover">
                                </a>
                            @else
                                <a href="{{ $mediaUrl }}" target="_blank" rel="noopener" class="flex items-center gap-2 rounded-xl bg-blue-700/60 px-3 py-2 hover:underline">
                                    <flux:icon icon="document-text" class="size-5 shrink-0" />
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium">{{ $item['media']['name'] }}</span>
                                        <span class="block text-xs opacity-80">{{ collect(['PDF', $item['media']['size'] ? \App\Services\OutboundMediaStorage::humanSize($item['media']['size']) : null])->filter()->implode(' · ') }}</span>
                                    </span>
                                </a>
                            @endif
                            @if (filled($item['text']))
                                <div class="px-2 pt-1.5 pb-0.5 whitespace-pre-line">{{ $item['text'] }}</div>
                            @endif
                        </div>
                    @else
                        <div class="rounded-2xl rounded-bl-md {{ $premium ? 'border border-zinc-200/80 bg-white shadow-sm shadow-zinc-950/5 dark:border-zinc-700/80 dark:bg-zinc-800' : 'bg-zinc-100 dark:bg-zinc-700' }} px-3.5 py-2.5 text-sm">
                            @include('livewire.tenant.leads.partials.attachment-item', ['lead' => $lead, 'attachment' => $item['attachment']])
                        </div>
                    @endif

                    @php
                        // "Sent by <name>" replaces the bare "Sent" status; any
                        // other status (Pending/Delivered/Read/Failed) still shows.
                        $status = $outgoing ? $item['status'] : null;
                        $sender = $outgoing ? $item['sender'] : null;
                        $caption = collect([
                            $sender ? __('Sent by :name', ['name' => $sender]) : null,
                            $status && ! ($sender && $status === \App\Enums\MessageStatus::Sent) ? __($status->label()) : null,
                        ])->filter()->implode(' · ');
                    @endphp
                    @if ($sentAt || $caption !== '')
                        <span class="max-w-full px-1 text-end text-[11px] font-medium leading-4 text-zinc-400 dark:text-zinc-500" @if ($sentAt) title="{{ $sentAt->format('M j, Y g:i A') }}" @endif>
                            {{ collect([$sentAt?->diffForHumans(), $caption])->filter()->implode(' · ') }}
                        </span>
                    @endif
                </div>
                </div>
            @endforeach
        </div>
    @elseif ($lead->isFacebookLeadAd())
        {{-- No fixed contact-form shape to fall back on (unlike every other
        source), so the raw Instant Form field_data — the same values the
        per-source spreadsheet view shows as columns — is the only thing
        worth showing here, laid out as label/value pairs instead of columns
        since this card only ever shows one lead at a time. --}}
        <flux:separator class="mb-4" />

        @if ($lead->source_data['form_name'] ?? null)
            <span class="mb-3 block text-xs sm:text-sm font-semibold text-zinc-700 dark:text-zinc-300">
                {{ __('Submitted via :form', ['form' => $lead->source_data['form_name']]) }}
            </span>
        @endif

        @php $fieldValues = $lead->leadAdFieldValues(); @endphp
        @if (count($fieldValues) > 0)
            <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach ($fieldValues as $field => $value)
                    <div class="min-w-0">
                        <dt class="text-xs font-semibold tracking-wide text-zinc-600 uppercase dark:text-zinc-400">
                            {{ ucwords(str_replace('_', ' ', $field)) }}
                        </dt>
                        <dd class="text-sm font-medium break-words text-zinc-900 dark:text-white">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        @else
            <span class="text-xs sm:text-sm font-medium text-zinc-600 dark:text-zinc-400">{{ __('No additional field data was submitted with this lead.') }}</span>
        @endif
    @elseif ($lead->message || $lead->html_body || $lead->attachments->isNotEmpty())
        <flux:separator class="mb-4" />

        @if ($lead->html_body)
            <div class="mb-3 flex gap-2">
                <flux:button size="xs" :variant="$bodyView === 'text' ? 'primary' : 'ghost'" wire:click="$set('bodyView', 'text')">
                    {{ __('Plain Text') }}
                </flux:button>
                <flux:button size="xs" :variant="$bodyView === 'html' ? 'primary' : 'ghost'" wire:click="$set('bodyView', 'html')">
                    {{ __('Original Email') }}
                </flux:button>
            </div>
        @endif

        @if ($bodyView === 'html' && $lead->html_body)
            {{-- sandbox with no `allow-*` tokens: no script execution, no
            same-origin/storage access, no navigation — the safest way to
            render HTML from an untrusted external sender. --}}
            <iframe
                srcdoc="{{ $lead->html_body }}"
                sandbox
                class="{{ $compact ? 'h-80' : 'h-[500px]' }} w-full rounded border border-zinc-200 bg-white dark:border-zinc-700"
            ></iframe>
        @elseif ($lead->message)
            {{-- Capped and internally scrollable, same reasoning as the
            conversation timeline above: a long plain-text submission (a
            website form's message field can be arbitrarily long) shouldn't
            be able to blow out the whole card's height uncapped. --}}
            <div class="{{ $compact ? 'max-h-48' : 'max-h-96' }} overflow-y-auto pe-1">
                <flux:text class="whitespace-pre-line text-zinc-900 dark:text-zinc-100 font-medium">{{ $lead->message }}</flux:text>
            </div>
        @endif

        @if ($lead->attachments->isNotEmpty())
            <div class="mt-3 flex flex-col gap-2">
                @foreach ($lead->attachments as $attachment)
                    @include('livewire.tenant.leads.partials.attachment-item', ['lead' => $lead, 'attachment' => $attachment])
                @endforeach
            </div>
        @endif
    @endif

    {{-- Additional Details: a Website Form lead's own extra fields, if the
    client's contact form had any beyond name/email/phone/message — laid
    out identically to Facebook Lead Ads' field grid above (no fixed shape
    to assume, so every key present is rendered under its own label,
    whatever the client's form happened to ask for). Deliberately its own
    block, not another @elseif branch: a Website Form lead's `message` (if
    its form has one) already rendered via the plain-message branch above,
    and this is complementary structured data alongside it, not a
    replacement for it. See Lead::websiteFormFieldValues(). --}}
    @if ($lead->isWebsiteForm())
        @php $extraFieldValues = $lead->websiteFormFieldValues(); @endphp
        @if (count($extraFieldValues) > 0)
            <flux:separator class="mb-4 mt-4" />

            <span class="mb-3 block text-xs sm:text-sm font-semibold text-zinc-800 dark:text-zinc-200">{{ __('Additional Details') }}</span>

            <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach ($extraFieldValues as $field => $value)
                    <div class="min-w-0">
                        <dt class="text-xs font-semibold tracking-wide text-zinc-600 uppercase dark:text-zinc-400">
                            {{ ucwords(str_replace(['_', '-'], ' ', $field)) }}
                        </dt>
                        <dd class="text-sm font-medium break-words text-zinc-900 dark:text-white">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    @endif
    </div>

    {{-- Skeleton: generic placeholder shape, hidden by default and revealed
    only while initialLoadComplete's request is in flight — see
    leads.blade.php for why this can't be a bare wire:loading directive.
    Deliberately generic rather than matching one specific branch: which of
    this card's several mutually-exclusive layouts (conversation feed, FB
    Instant Form fields, email body, plain message) will actually render
    isn't knowable until $lead->messageTimeline()/isFacebookLeadAd()/etc.
    are evaluated above, and this toggle doesn't touch any of that. --}}
    <div class="{{ $showSkeletonByDefault ? '' : '!hidden' }} flex flex-col gap-3" wire:loading.remove.class="!hidden" wire:target="{{ $listLoadingTargets }}">
        <div class="h-3 w-full skeleton-shimmer rounded"></div>
        <div class="h-3 w-5/6 skeleton-shimmer rounded"></div>
        <div class="h-3 w-2/3 skeleton-shimmer rounded"></div>
    </div>
</flux:card>
