{{-- Full-page-only (Overview tab, show.blade.php — never show-panel.blade.php's
flyout): combines what used to be two separate cards —
detail-card.blade.php's premium conversation thread and
message-composer-card.blade.php's reply box — into one Messenger-style
card (thread above, composer fixed at the bottom), at the user's request.
Both halves below are carried over verbatim from those two files (same
markup, same Alpine, same wire: bindings) — only the surrounding card
chrome (one header instead of two) changed. detail-card.blade.php and
message-composer-card.blade.php themselves are untouched, since both are
still used as-is by show-panel.blade.php's flyout; this file is NOT a
replacement for either, it's a third, dedicated view that only ever
renders when $this->messagingChannel is present (same condition
message-composer-card.blade.php itself already self-guards on). The
Sent/Failed/Resend activity log that used to live at the bottom of the
composer card now lives in its own outbound-activity-log-card.blade.php
instead — see that file for why. --}}
@php
    $channel = $this->messagingChannel;
@endphp

@if ($channel)
    @php
        $eligibility = $this->messagingEligibility;
        $canUpdate = $this->canUpdate;
        $hasConversation = count($lead->messageTimeline()) > 0;
        $listLoadingTargets = 'switchTab,markTabContentReady';
        $showSkeletonByDefault = ! $tabContentReady;
    @endphp

    <flux:card class="flex h-full max-h-[34rem] flex-col gap-4">
        <div class="-mx-6 -mt-6 mb-1 flex items-center justify-between gap-3 border-b border-zinc-200/80 bg-[linear-gradient(115deg,rgba(255,255,255,0.96)_0%,rgba(248,250,252,0.92)_48%,rgba(239,246,255,0.88)_100%)] px-6 py-4 dark:border-zinc-700/80 dark:bg-[linear-gradient(115deg,rgba(24,24,27,0.98)_0%,rgba(24,24,27,0.94)_48%,rgba(23,37,84,0.76)_100%)]">
            <div class="flex min-w-0 items-center gap-3">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-blue-600 shadow-lg shadow-blue-600/20">
                    <flux:icon icon="chat-bubble-left-right" class="size-5 text-white" />
                </div>
                <div>
                    <flux:heading size="lg" class="tracking-tight">{{ __(':channel Reply', ['channel' => $channel->label()]) }}</flux:heading>
                    <flux:text size="sm" variant="subtle">{{ __('Lead communication history') }}</flux:text>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                @if ($hasConversation)
                    <span class="hidden rounded-full border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-500 sm:inline-flex dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">
                        {{ trans_choice(':count message|:count messages', count($lead->messageTimeline()), ['count' => count($lead->messageTimeline())]) }}
                    </span>
                @endif

                @if ($this->canDeleteConversation)
                    <flux:button type="button" size="xs" variant="ghost" icon="trash" wire:click="confirmDeleteConversation">{{ __('Delete conversation') }}</flux:button>
                @endif
            </div>
        </div>

        {{-- Real content: hidden by default before tabContentReady, toggled
        by wire:loading otherwise — never a bare wire:loading/.remove, see
        leads.blade.php's long comment for why. --}}
        <div wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" class="{{ $showSkeletonByDefault ? '!hidden' : '' }} flex min-h-0 flex-1 flex-col gap-4">
            @if ($hasConversation)
                {{-- ── Conversation thread — carried over verbatim from
                detail-card.blade.php's premium branch. ── --}}

                {{-- WhatsApp-style "N new message(s)" pill: the feed div below keeps
                auto-scrolling to the bottom as before WHEN the viewer is already
                near it, but once they've scrolled up to read older messages, a new
                arrival no longer yanks them back down — it just grows this pill
                instead, absolutely positioned over the feed (a sibling, outside the
                scrollable div, so it never scrolls away with the content). Clicking
                it scrolls to bottom and clears the count; scrolling down to the
                bottom yourself clears it too. isNearBottom's 80px slack matches
                "close enough that a reply bubble appearing is not a surprise." --}}
                <div
                    class="relative flex min-h-0 flex-1 flex-col"
                    x-data="{
                        newCount: 0,
                        // Tracked continuously by the scroll listener below, NOT
                        // re-measured inside the MutationObserver callback — by the
                        // time that callback runs, the new message has already been
                        // appended and scrollHeight has already grown, so comparing
                        // scrollHeight/scrollTop/clientHeight at that point always
                        // reads as 'not near bottom' the instant anything arrives,
                        // even for a viewer who was sitting right at the bottom a
                        // moment before. This flag instead reflects where they were
                        // right before the mutation happened.
                        nearBottom: true,
                        isNearBottom(el) { return el.scrollHeight - el.scrollTop - el.clientHeight < 80 },
                        scrollToBottom(el) { el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' }); this.newCount = 0; this.nearBottom = true },
                    }"
                >
                    <div
                        x-ref="feed"
                        x-init="
                            // This lives inside the flyout dialog, which is display:none
                            // until opened — scrollHeight reads as 0 at Alpine's init
                            // time, before the panel is ever visible, so a plain
                            // x-init scroll-to-bottom silently no-ops. IntersectionObserver
                            // instead fires each time the element actually becomes
                            // visible (i.e. every time the panel opens), which is also
                            // exactly when 'always default to the bottom' should apply.
                            new IntersectionObserver((entries) => {
                                if (entries[0].isIntersecting) { $el.scrollTop = $el.scrollHeight; newCount = 0; nearBottom = true }
                            }).observe($el);
                            new MutationObserver((mutations) => {
                                if (nearBottom) {
                                    $el.scrollTop = $el.scrollHeight;
                                    return;
                                }
                                // Scoped to nodes added directly to the feed itself
                                // (one per rendered message/attachment row) — the
                                // observer stays { subtree: true } so a date-divider
                                // or nested content change still triggers the
                                // near-bottom autoscroll above, but counting every
                                // nested mutation here would wildly overcount a
                                // single new message's own internal spans/icons.
                                let added = 0;
                                mutations.forEach((m) => {
                                    if (m.target === $el) {
                                        m.addedNodes.forEach((n) => { if (n.nodeType === 1) { added++ } });
                                    }
                                });
                                newCount += added;
                            }).observe($el, { childList: true, subtree: true });
                        "
                        x-on:scroll.passive="nearBottom = isNearBottom($el); if (nearBottom) { newCount = 0 }"
                        class="flex max-h-96 rounded-2xl border border-white/80 bg-[radial-gradient(105%_82%_at_100%_0%,rgba(219,234,254,0.72)_0%,rgba(219,234,254,0)_62%),radial-gradient(86%_82%_at_0%_100%,rgba(237,233,254,0.52)_0%,rgba(237,233,254,0)_66%),linear-gradient(135deg,rgba(255,255,255,0.92)_0%,rgba(248,250,252,0.84)_52%,rgba(239,246,255,0.88)_100%)] p-3 shadow-inner shadow-slate-950/5 ring-1 ring-zinc-200/70 sm:p-4 dark:border-white/10 dark:bg-[radial-gradient(105%_82%_at_100%_0%,rgba(30,58,138,0.34)_0%,rgba(30,58,138,0)_62%),radial-gradient(86%_82%_at_0%_100%,rgba(76,29,149,0.18)_0%,rgba(76,29,149,0)_66%),linear-gradient(135deg,rgba(9,9,11,0.96)_0%,rgba(24,24,27,0.92)_52%,rgba(15,23,42,0.94)_100%)] dark:shadow-black/35 dark:ring-white/5 min-h-0 w-full flex-1 flex-col gap-4 overflow-y-auto pe-1 contain-paint"
                    >
                    {{-- Messenger-style feed: what the customer sent (and every
                    attachment, which only ever arrives inbound) sits on the left in
                    a neutral bubble; what this business sent sits on the right in a
                    blue bubble, with its delivery status under it. Presentation
                    only — the order, the data and the scroll behavior above are
                    unchanged. --}}
                    @php $previousMessageDate = null; $previousOutgoing = null; $previousSender = null; $previousSentAt = null; @endphp
                    @foreach ($this->timeline as $item)
                        @php
                            $outgoing = $item['type'] !== 'attachment' && $item['direction'] === \App\Enums\MessageDirection::Outbound;
                            $sentAt = $item['at'] ?? null;
                            $sender = $outgoing ? $item['sender'] : null;
                        @endphp

                        @if ($sentAt && (! $previousMessageDate || ! $sentAt->isSameDay($previousMessageDate)))
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
                            {{-- A day boundary always breaks grouping, even if the
                            same side/sender continues right after it. --}}
                            @php $previousOutgoing = null; $previousSender = null; $previousSentAt = null; @endphp
                        @endif
                        @php $previousMessageDate = $sentAt ?? $previousMessageDate; @endphp

                        @php
                            // Consecutive messages from the same side (and, for
                            // outgoing, the same team member — a sender change
                            // always starts a fresh group), sent within a minute of
                            // each other, collapse their repeated "YOUR TEAM" label
                            // and "Sent by :name" caption into a single header,
                            // WhatsApp-style, rather than restating it on every
                            // single bubble — that repetition (plus a full gap-4
                            // between every message regardless of how closely
                            // related) is what made a rapid back-and-forth look
                            // over-padded. The 60s cutoff is deliberately tight: two
                            // genuinely separate replies a couple of minutes apart
                            // should still each get their own label/timestamp.
                            $isGrouped = $loop->index > 0
                                && $outgoing === $previousOutgoing
                                && $sender === $previousSender
                                && $sentAt && $previousSentAt
                                && abs($sentAt->diffInSeconds($previousSentAt)) <= 60;
                            $previousOutgoing = $outgoing;
                            $previousSender = $sender;
                            $previousSentAt = $sentAt ?? $previousSentAt;
                        @endphp

                        {{-- The feed container's own gap-4 already spaces every row
                        apart by default (and correctly adds nothing before the very
                        first child) — a grouped row only needs to pull itself
                        closer to the previous one, via a negative margin sized to
                        net out to a tight ~4px gap instead of the full 16px. --}}
                        <div class="flex w-full items-end gap-2.5 {{ $isGrouped ? '-mt-3' : '' }} {{ $outgoing ? 'justify-end' : 'justify-start' }}">
                            @if (! $outgoing)
                                <div class="mb-5 flex size-8 shrink-0 items-center justify-center rounded-xl border border-white bg-white text-zinc-500 shadow-sm ring-1 ring-zinc-200/70 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-700 {{ $isGrouped ? 'invisible' : '' }}">
                                    <flux:icon :icon="$lead->source->type->icon()" class="size-4" />
                                </div>
                            @endif

                        <div class="flex max-w-[82%] flex-col gap-1 {{ $outgoing ? 'items-end' : 'items-start' }}">
                            @if (! $isGrouped)
                                <span class="px-1 text-[10px] font-bold tracking-wide text-zinc-400 uppercase dark:text-zinc-500">{{ $outgoing ? __('Your team') : $lead->source->name }}</span>
                            @endif
                            @if ($item['type'] === 'text')
                                <div @class([
                                    'w-fit min-w-14 rounded-2xl px-3.5 py-2.5 text-sm leading-6 break-words whitespace-pre-line shadow-sm',
                                    'rounded-br-md bg-linear-to-br from-blue-600 to-indigo-600 text-white shadow-blue-600/20' => $outgoing,
                                    'rounded-bl-md border border-zinc-200/80 bg-white text-zinc-900 shadow-zinc-950/5 dark:border-zinc-700/80 dark:bg-zinc-800 dark:text-zinc-100' => ! $outgoing,
                                ])><span class="sr-only">{{ $outgoing ? __('You') : __('Customer') }}:</span>{{ $item['text'] }}</div>
                            @elseif ($item['type'] === 'media')
                                @php
                                    $mediaUrl = route('tenant-leads.message-attachment', [$lead, $item['media']['message_id']]);
                                @endphp
                                <div class="rounded-2xl rounded-br-md bg-linear-to-br from-blue-600 to-indigo-600 shadow-sm shadow-blue-600/20 p-1.5 text-sm break-words text-white">
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
                                <div class="rounded-2xl rounded-bl-md border border-zinc-200/80 bg-white shadow-sm shadow-zinc-950/5 dark:border-zinc-700/80 dark:bg-zinc-800 px-3.5 py-2.5 text-sm">
                                    @include('livewire.tenant.leads.partials.attachment-item', ['lead' => $lead, 'attachment' => $item['attachment']])
                                </div>
                            @endif

                            @php
                                // "Sent by <name>" replaces the bare "Sent" status; any
                                // other status (Pending/Delivered/Read/Failed) still shows.
                                // Grouped messages skip "Sent by :name" (already stated
                                // once at the top of the group — see $isGrouped above)
                                // but keep their own per-message status, which can
                                // still legitimately differ bubble to bubble.
                                $status = $outgoing ? $item['status'] : null;
                                $caption = collect([
                                    $sender && ! $isGrouped ? __('Sent by :name', ['name' => $sender]) : null,
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

                    <button
                        type="button"
                        x-show="newCount > 0"
                        x-transition.opacity
                        x-on:click="scrollToBottom($refs.feed)"
                        x-cloak
                        class="absolute bottom-3 left-1/2 flex -translate-x-1/2 items-center gap-1.5 rounded-full bg-emerald-600 px-3.5 py-1.5 text-xs font-semibold text-white shadow-lg shadow-emerald-600/30 transition-transform hover:scale-105 cursor-pointer"
                    >
                        <flux:icon icon="arrow-down" variant="micro" class="size-3.5" />
                        <span x-text="newCount === 1 ? @js(__('1 new message')) : newCount + ' ' + @js(__('new messages'))"></span>
                    </button>
                </div>
            @elseif ($lead->message || $lead->html_body || $lead->attachments->isNotEmpty())
                {{-- ── Fallback — carried over verbatim from
                detail-card.blade.php's own third branch (its
                $lead->isFacebookLeadAd() branch is dropped here: a lead
                reaching this file always has a messaging $channel, which
                FacebookLeadAds never does, so that branch could never be
                reached from here). $hasConversation above is based on
                Lead::messageTimeline() specifically, which doesn't
                recognize every shape $this->timeline() does (e.g. an
                attachment with no accompanying recognized text message) —
                this is what the original file's identical fallback
                already existed to catch, not something introduced by
                combining the cards. ── --}}
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
                    <iframe
                        srcdoc="{{ $lead->html_body }}"
                        sandbox
                        class="h-[500px] w-full rounded border border-zinc-200 bg-white dark:border-zinc-700"
                    ></iframe>
                @elseif ($lead->message)
                    <div class="max-h-96 overflow-y-auto pe-1">
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
            @else
                <div class="flex flex-1 items-center justify-center">
                    <flux:text variant="subtle" size="sm">{{ __('No messages yet.') }}</flux:text>
                </div>
            @endif

            {{-- ── Composer — carried over verbatim from
            message-composer-card.blade.php. ── --}}
            @if ($canUpdate)
                @if ($eligibility && $eligibility->eligible)
                    @php
                        $pendingUpload = $this->imageUpload ?? $this->pdfUpload;
                        $uploadTargets = 'imageUpload,pdfUpload';
                    @endphp
                    <form wire:submit="sendMessage" x-data class="flex flex-col gap-2">
                        <flux:textarea wire:model="newMessageBody" :placeholder="__('Type a reply…')" rows="3" />
                        @error('newMessageBody') <flux:text size="sm" class="text-red-600 dark:text-red-400">{{ $message }}</flux:text> @enderror

                        @if ($pendingUpload)
                            <div class="flex items-center justify-between gap-2 rounded-lg bg-zinc-100 px-3 py-2 dark:bg-zinc-700">
                                <div class="flex min-w-0 items-center gap-2">
                                    <flux:icon :icon="$this->imageUpload ? 'photo' : 'document-text'" class="size-5 shrink-0" />
                                    <span class="truncate text-sm">{{ $pendingUpload->getClientOriginalName() }}</span>
                                    <span class="shrink-0 text-xs text-zinc-500">{{ \App\Services\OutboundMediaStorage::humanSize((int) $pendingUpload->getSize()) }}</span>
                                </div>
                                <flux:button type="button" size="xs" variant="ghost" icon="x-mark" wire:click="removeUpload" :aria-label="__('Remove file')" />
                            </div>
                        @endif

                        @error('imageUpload') <flux:text size="sm" class="text-red-600 dark:text-red-400">{{ $message }}</flux:text> @enderror
                        @error('pdfUpload') <flux:text size="sm" class="text-red-600 dark:text-red-400">{{ $message }}</flux:text> @enderror
                        <flux:text size="sm" variant="subtle" wire:loading wire:target="{{ $uploadTargets }}">{{ __('Uploading…') }}</flux:text>

                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <input type="file" class="hidden" x-ref="imageInput" wire:model="imageUpload" accept="{{ implode(',', $channel->imageMimeTypes()) }}">
                                <input type="file" class="hidden" x-ref="pdfInput" wire:model="pdfUpload" accept="application/pdf">
                                <flux:button type="button" size="sm" icon="photo" x-on:click="$refs.imageInput.value = ''; $refs.imageInput.click()">{{ __('Image') }}</flux:button>
                                <flux:button type="button" size="sm" icon="document-text" x-on:click="$refs.pdfInput.value = ''; $refs.pdfInput.click()">{{ __('PDF') }}</flux:button>
                            </div>
                            <flux:button type="submit" variant="primary" size="sm" wire:loading.attr="disabled" wire:target="{{ $uploadTargets }}">{{ __('Send') }}</flux:button>
                        </div>
                    </form>
                @else
                    @php
                        // Message Templates (WhatsApp or Messenger) — the
                        // composer's recovery path once the window has closed.
                        // templateEligibility being eligible means "every other
                        // check (merged, consent, credentials) passed — only
                        // the window itself is what's blocking the normal
                        // composer", so this never offers a template for a
                        // merged/consent-withdrawn lead, without needing to
                        // string-match $eligibility->reason.
                        $templateEligibility = $this->templateEligibility;
                        $templates = match ($channel) {
                            \App\Enums\MessageChannel::Whatsapp => $this->whatsappTemplates,
                            \App\Enums\MessageChannel::Messenger => $this->messengerTemplates,
                            default => collect(),
                        };

                        // Everything the picker/field-count/preview below need,
                        // handed to Alpine once so picking a template never
                        // needs a server round trip (the field count/preview
                        // used to require one, via wire:model.live — this is
                        // what made the fields feel slow to appear). bodyText
                        // is only ever populated for a Messenger template (see
                        // MessengerMessageTemplate's own docblock); a WhatsApp
                        // template is registered, never authored, in this app,
                        // so its wording is never known here — the preview box
                        // below simply doesn't render for one.
                        $templatesPayload = $templates->map(fn ($template) => [
                            'id' => $template->id,
                            'name' => $template->name,
                            'language' => $template->language,
                            'variableCount' => $template->variable_count,
                            'bodyText' => $template instanceof \App\Models\MessengerMessageTemplate ? $template->body_text : null,
                        ])->values();
                    @endphp

                    @if ($templateEligibility && $templateEligibility->eligible && $templates->isNotEmpty())
                        <flux:callout variant="subtle" icon="clock" :heading="__('The reply window has expired')">
                            <flux:callout.text>
                                {{ __('Send an approved template to re-engage this lead — once they reply, free-form replies work again.') }}
                            </flux:callout.text>
                        </flux:callout>

                        <form
                            wire:submit="sendTemplateMessage"
                            class="flex flex-col gap-2"
                            x-data="{
                                templates: @js($templatesPayload),
                                selectedId: $wire.entangle('selectedTemplateId'),
                                values: $wire.entangle('templateVariableValues'),
                                get selected() {
                                    return this.templates.find((t) => t.id === this.selectedId) ?? null;
                                },
                                get variableNumbers() {
                                    return this.selected ? Array.from({ length: this.selected.variableCount }, (_, i) => i + 1) : [];
                                },
                                placeholderLabel(n) {
                                    const openBraces = '{' + '{';
                                    const closeBraces = '}' + '}';
                                    return 'Placeholder ' + openBraces + n + closeBraces;
                                },
                                get preview() {
                                    if (! this.selected || ! this.selected.bodyText) { return null; }
                                    const openBraces = '{' + '{';
                                    const closeBraces = '}' + '}';
                                    let text = this.selected.bodyText;
                                    this.variableNumbers.forEach((n) => {
                                        const token = openBraces + n + closeBraces;
                                        const value = this.values[n - 1];
                                        text = text.split(token).join(value ? value : token);
                                    });
                                    return text;
                                },
                            }"
                            x-init="$watch('selectedId', () => { values = variableNumbers.map(() => ''); })"
                        >
                            <x-dropdown-select
                                wire:model="selectedTemplateId"
                                :placeholder="__('Choose a template…')"
                                :options="$templates->map(fn ($template) => ['value' => $template->id, 'label' => __(':name (:language)', ['name' => $template->name, 'language' => $template->language])])->toArray()"
                            />

                            <div x-show="selected" x-cloak class="flex flex-col gap-2">
                                <template x-for="n in variableNumbers" :key="n">
                                    <div class="flex flex-col gap-1">
                                        <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300" x-text="placeholderLabel(n)"></label>
                                        <input
                                            type="text"
                                            x-model="values[n - 1]"
                                            :placeholder="placeholderLabel(n)"
                                            class="w-full rounded-lg border border-zinc-300/80 bg-zinc-100/90 px-3 py-1.5 text-sm text-zinc-800 shadow-xs focus:outline-none focus:ring-2 focus:ring-accent/40 dark:border-zinc-700/80 dark:bg-zinc-900/90 dark:text-zinc-200"
                                        >
                                    </div>
                                </template>

                                <div x-show="preview" x-cloak class="rounded-lg border border-zinc-200/80 bg-zinc-50 p-3 dark:border-zinc-700/80 dark:bg-zinc-900/60">
                                    <flux:text size="xs" variant="subtle">{{ __('Preview') }}</flux:text>
                                    <p class="text-sm text-zinc-700 dark:text-zinc-300" x-text="preview"></p>
                                </div>

                                @error('templateVariableValues') <flux:text size="sm" class="text-red-600 dark:text-red-400">{{ $message }}</flux:text> @enderror
                                @foreach ($errors->get('templateVariableValues.*') as $fieldErrors)
                                    @foreach ($fieldErrors as $fieldError)
                                        <flux:text size="sm" class="text-red-600 dark:text-red-400">{{ $fieldError }}</flux:text>
                                    @endforeach
                                @endforeach

                                <div class="flex justify-end">
                                    <flux:button type="submit" variant="primary" size="sm">{{ __('Send Template') }}</flux:button>
                                </div>
                            </div>
                        </form>
                    @else
                        <flux:callout variant="subtle" icon="lock-closed" :heading="__('Sending is not available right now')">
                            <flux:callout.text>
                                {{ $eligibility?->reason ?? __('This channel has no conversation history yet.') }}
                            </flux:callout.text>
                        </flux:callout>
                    @endif
                @endif
            @endif
        </div>

        {{-- Skeleton: hidden by default, revealed only while
        tabContentReady's request is in flight. --}}
        <div class="{{ $showSkeletonByDefault ? '' : '!hidden' }} flex flex-col gap-3" wire:loading.remove.class="!hidden" wire:target="{{ $listLoadingTargets }}">
            <div class="h-24 w-full skeleton-shimmer rounded-2xl"></div>
            <div class="h-16 w-2/3 skeleton-shimmer rounded-2xl"></div>
            <div class="h-20 w-full skeleton-shimmer rounded-lg"></div>
        </div>
    </flux:card>

    @if ($this->showDeleteConversationModal && $this->canDeleteConversation)
        @php
            $deletionSummary = $this->conversationDeletionSummary;
        @endphp

        <x-confirm-password-delete-modal
            wire:model.self="showDeleteConversationModal"
            :title="__('Delete this conversation?')"
            :description="__('This permanently deletes :messages messages and :files files from this lead\'s conversation, including the files stored on the server. The lead itself is kept. Enter your password to confirm.', ['messages' => $deletionSummary['messages'], 'files' => $deletionSummary['files']])"
            confirm-action="deleteConversationConfirmed"
            close-action="closeDeleteConversationModal"
        />
    @endif
@endif
