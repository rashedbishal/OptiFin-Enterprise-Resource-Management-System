@php $compact ??= false; @endphp

<flux:card>
    <flux:heading size="lg" class="mb-4">{{ __('Details') }}</flux:heading>

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

    @php
        $hasCrmDetails = collect([
            $lead->website, $lead->industry, $lead->location, $lead->service_interest,
            $lead->estimated_budget, $lead->deal_value, $lead->win_probability, $lead->next_follow_up_at,
        ])->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty();
    @endphp
    @if ($hasCrmDetails)
        <flux:separator class="mb-4" />
        <div class="mb-4 grid grid-cols-2 gap-x-4 gap-y-3 {{ $compact ? '' : 'sm:grid-cols-3' }}">
            @if ($lead->website)
                <div>
                    <flux:text size="sm" variant="subtle">{{ __('Website') }}</flux:text>
                    <flux:text><a href="{{ $lead->website }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $lead->website }}</a></flux:text>
                </div>
            @endif
            @if ($lead->industry)
                <div>
                    <flux:text size="sm" variant="subtle">{{ __('Industry') }}</flux:text>
                    <flux:text>{{ $lead->industry }}</flux:text>
                </div>
            @endif
            @if ($lead->location)
                <div>
                    <flux:text size="sm" variant="subtle">{{ __('Location') }}</flux:text>
                    <flux:text>{{ $lead->location }}</flux:text>
                </div>
            @endif
            @if ($lead->service_interest)
                <div>
                    <flux:text size="sm" variant="subtle">{{ __('Service Interested') }}</flux:text>
                    <flux:text>{{ $lead->service_interest }}</flux:text>
                </div>
            @endif
            @if ($lead->estimated_budget !== null)
                <div>
                    <flux:text size="sm" variant="subtle">{{ __('Est. Budget') }}</flux:text>
                    <flux:text>{{ $lead->tenant->currency }} {{ number_format((float) $lead->estimated_budget, 2) }}</flux:text>
                </div>
            @endif
            @if ($lead->deal_value !== null)
                <div>
                    <flux:text size="sm" variant="subtle">{{ __('Deal Value') }}</flux:text>
                    <flux:text>{{ $lead->tenant->currency }} {{ number_format((float) $lead->deal_value, 2) }}</flux:text>
                </div>
            @endif
            @if ($lead->win_probability !== null)
                <div>
                    <flux:text size="sm" variant="subtle">{{ __('Win Probability') }}</flux:text>
                    <flux:text>{{ $lead->win_probability }}%</flux:text>
                </div>
            @endif
            @if ($lead->next_follow_up_at)
                <div>
                    <flux:text size="sm" variant="subtle">{{ __('Next Follow-up') }}</flux:text>
                    <flux:text>{{ $lead->next_follow_up_at->format('M j, Y') }}</flux:text>
                </div>
            @endif
        </div>
    @endif

    @if (count($lead->messageTimeline()) > 0)
        {{-- Webhook-sourced leads (Messenger/Instagram/WhatsApp) can carry
        several text messages and attachments over time — shown here as a
        single feed, in the order they actually arrived, rather than as a
        flattened message blob with attachments clustered separately. Gated
        on messageTimeline() specifically (not just "has any attachment"):
        an email lead can have attachments too, but it isn't a threaded
        conversation and should keep its own body/HTML rendering below. --}}
        @unless ($hasCrmDetails)
            <flux:separator class="mb-4" />
        @endunless

        <div class="flex max-h-96 flex-col gap-3 overflow-y-auto pe-1">
            @foreach ($timeline as $item)
                @if ($item['type'] === 'text')
                    <flux:text class="whitespace-pre-line">{{ $item['text'] }}</flux:text>
                @else
                    @include('livewire.tenant.leads.partials.attachment-item', ['lead' => $lead, 'attachment' => $item['attachment']])
                @endif
            @endforeach
        </div>
    @elseif ($lead->message || $lead->html_body || $lead->attachments->isNotEmpty())
        @unless ($hasCrmDetails)
            <flux:separator class="mb-4" />
        @endunless

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
                class="{{ $compact ? 'h-80' : 'h-[500px]' }} w-full rounded border border-neutral-200 bg-white dark:border-neutral-700"
            ></iframe>
        @elseif ($lead->message)
            <flux:text class="whitespace-pre-line">{{ $lead->message }}</flux:text>
        @endif

        @if ($lead->attachments->isNotEmpty())
            <div class="mt-3 flex flex-col gap-2">
                @foreach ($lead->attachments as $attachment)
                    @include('livewire.tenant.leads.partials.attachment-item', ['lead' => $lead, 'attachment' => $attachment])
                @endforeach
            </div>
        @endif
    @endif
</flux:card>
