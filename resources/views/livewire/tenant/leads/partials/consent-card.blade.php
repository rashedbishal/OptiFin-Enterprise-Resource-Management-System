@php
    // Rendered as part of App\Livewire\Tenant\Leads\Show's own view tree
    // (included from both show.blade.php and show-panel.blade.php), so
    // $this is the Show component instance — same "call $this->x directly
    // in a shared partial" pattern custom-fields-card.blade.php/deals-card.
    // blade.php already use.
    $canUpdate = $this->canUpdate;
    $consents = $this->currentConsents;
@endphp

<flux:card class="flex flex-col gap-4">
    <flux:heading size="lg">{{ __('Consent') }}</flux:heading>

    <div class="flex flex-col gap-3">
        @foreach (\App\Enums\ConsentType::cases() as $type)
            @php($consent = $consents->get($type->value))
            <div class="flex items-center justify-between gap-2" wire:key="consent-row-{{ $type->value }}">
                <div>
                    <flux:text variant="strong">{{ $type->label() }}</flux:text>
                    <div class="mt-1 flex items-center gap-2">
                        <flux:badge size="sm" :color="match (true) {
                            $consent === null => 'zinc',
                            $consent->status->value === 'granted' => 'emerald',
                            default => 'red',
                        }">
                            {{ $consent?->status->label() ?? __('Not Provided') }}
                        </flux:badge>
                        @if ($consent)
                            <flux:text size="sm" variant="subtle">
                                {{ $consent->occurred_at->diffForHumans() }}
                                ·
                                {{ $consent->source->value === 'website_form' ? __('Website Form: :name', ['name' => $consent->website_form_name ?? __('unknown')]) : $consent->source->label() }}
                            </flux:text>
                        @endif
                    </div>
                </div>

                @if ($canUpdate)
                    @if ($consent?->status->value === 'granted')
                        <flux:button size="sm" variant="danger" wire:click="withdrawConsent('{{ $type->value }}')" wire:loading.attr="disabled" wire:target="withdrawConsent('{{ $type->value }}')">{{ __('Withdraw') }}</flux:button>
                    @else
                        <flux:button size="sm" wire:click="grantConsent('{{ $type->value }}')" wire:loading.attr="disabled" wire:target="grantConsent('{{ $type->value }}')">{{ __('Grant') }}</flux:button>
                    @endif
                @endif
            </div>
        @endforeach
    </div>

    @if ($this->consentHistory->isNotEmpty())
        <flux:separator />

        <div>
            <flux:text size="sm" variant="subtle">{{ __('History') }}</flux:text>
            <div class="mt-2 flex max-h-48 flex-col gap-2 overflow-y-auto pe-1">
                @foreach ($this->consentHistory as $event)
                    <flux:text size="sm" class="text-xs" wire:key="consent-history-{{ $event->id }}">
                        <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ $event->consent_type->label() }}</span>
                        {{ strtolower($event->status->label()) }}
                        <span class="text-zinc-500">
                            — {{ $event->changedBy?->name ?? ($event->source->value === 'website_form' ? __('Website Form: :name', ['name' => $event->website_form_name ?? __('unknown')]) : $event->source->label()) }}
                            · {{ $event->occurred_at->diffForHumans() }}
                        </span>
                    </flux:text>
                @endforeach
            </div>
        </div>
    @endif
</flux:card>
