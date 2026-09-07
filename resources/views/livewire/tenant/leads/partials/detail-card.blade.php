@php
    $compact ??= false;

    // Self-contained (like activity-card.blade.php) since this partial is
    // shared by two callers (show.blade.php, show-panel.blade.php) that
    // both need the identical pair. This is purely a visual
    // reveal-together toggle, not a real query defer — see
    // lead-hero.blade.php's identical pair for why: $lead's fields
    // (including messageTimeline()/attachments below) are already
    // resolved by the time this renders, nothing here is deferred.
    // Deliberately does NOT gate $this->timeline itself (see
    // Show::$initialLoadComplete's docblock for why that would be unsafe:
    // the @if below branches on $lead->messageTimeline() directly, not on
    // $timeline, so gating the latter alone would leave this @if
    // evaluating true while iterating an empty array — a blank box, not a
    // skeleton) — only this card's overall visibility is toggled, all of
    // its existing branching is untouched.
    $listLoadingTargets = 'completeInitialLoad';
    $showSkeletonByDefault = ! $initialLoadComplete;
@endphp

<flux:card>
    <flux:heading size="lg" class="mb-4">{{ __('Details') }}</flux:heading>

    {{-- Real content: hidden by default before initialLoadComplete, toggled
    by wire:loading otherwise — never a bare wire:loading/.remove, see
    leads.blade.php's long comment for why. --}}
    <div wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" class="{{ $showSkeletonByDefault ? '!hidden' : '' }} contents">
    <div class="mb-4 flex items-center gap-3">
        <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-700">
            <flux:icon :icon="$lead->source->type->icon()" class="size-5 text-zinc-500 dark:text-zinc-300" />
        </div>
        <div>
            <flux:text variant="strong">{{ $lead->source->name }}</flux:text>
            <flux:text size="sm" variant="subtle">
                {{ $lead->source->type->label() }}
                @if ($lead->created_at)
                    &middot; {{ $lead->created_at->diffForHumans() }}
                @endif
            </flux:text>
        </div>
    </div>

    @if (count($lead->messageTimeline()) > 0)
        {{-- Webhook-sourced leads (Messenger/Instagram/WhatsApp) can carry
        several text messages and attachments over time — shown here as a
        single feed, in the order they actually arrived, rather than as a
        flattened message blob with attachments clustered separately. Gated
        on messageTimeline() specifically (not just "has any attachment"):
        an email lead can have attachments too, but it isn't a threaded
        conversation and should keep its own body/HTML rendering below. --}}
        <flux:separator class="mb-4" />

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
            class="flex {{ $compact ? 'max-h-48' : 'max-h-96' }} flex-col gap-3 overflow-y-auto pe-1"
        >
            @foreach ($timeline as $item)
                @if ($item['type'] === 'text')
                    <flux:text class="whitespace-pre-line">{{ $item['text'] }}</flux:text>
                @else
                    @include('livewire.tenant.leads.partials.attachment-item', ['lead' => $lead, 'attachment' => $item['attachment']])
                @endif
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
            <flux:text size="sm" variant="subtle" class="mb-3 block">
                {{ __('Submitted via :form', ['form' => $lead->source_data['form_name']]) }}
            </flux:text>
        @endif

        @php $fieldValues = $lead->leadAdFieldValues(); @endphp
        @if (count($fieldValues) > 0)
            <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach ($fieldValues as $field => $value)
                    <div class="min-w-0">
                        <dt class="text-[10px] font-medium tracking-wide text-zinc-400 uppercase dark:text-zinc-500">
                            {{ ucwords(str_replace('_', ' ', $field)) }}
                        </dt>
                        <dd class="text-sm break-words text-zinc-700 dark:text-zinc-200">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        @else
            <flux:text size="sm" variant="subtle">{{ __('No additional field data was submitted with this lead.') }}</flux:text>
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
                <flux:text class="whitespace-pre-line">{{ $lead->message }}</flux:text>
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
