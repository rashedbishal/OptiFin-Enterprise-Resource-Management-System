@php
    // Rendered as part of App\Livewire\Tenant\Leads\Show's own view tree —
    // same "call $this->x directly in a shared partial" pattern
    // consent-card.blade.php/custom-fields-card.blade.php already use.
    // Nothing renders at all for a lead whose own source isn't a
    // conversational channel (Website Form, Email, Manual, etc.) — no
    // unified inbox, no cross-channel composer, per the approved
    // Architecture Brief.
    $channel = $this->messagingChannel;
@endphp

@if ($channel)
    @php
        $eligibility = $this->messagingEligibility;
        $canUpdate = $this->canUpdate;
    @endphp

    <flux:card class="flex flex-col gap-4">
        <flux:heading size="lg">{{ __(':channel Reply', ['channel' => $channel->label()]) }}</flux:heading>

        @if ($canUpdate)
            @if ($eligibility && $eligibility->eligible)
                <form wire:submit="sendMessage" class="flex flex-col gap-2">
                    <flux:textarea wire:model="newMessageBody" :placeholder="__('Type a reply…')" rows="3" />
                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary" size="sm">{{ __('Send') }}</flux:button>
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

        @if ($this->outboundMessages->isNotEmpty())
            <flux:separator />

            <div class="flex flex-col gap-2">
                @foreach ($this->outboundMessages as $message)
                    <div class="flex items-start justify-between gap-2" wire:key="outbound-message-{{ $message->id }}">
                        <div>
                            <flux:text>{{ $message->body }}</flux:text>
                            <flux:text size="sm" variant="subtle">
                                {{ $message->sentBy?->name ?? __('System') }} · {{ $message->created_at?->diffForHumans() }}
                            </flux:text>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <flux:badge size="sm" :color="match ($message->status?->value) {
                                'sent' => 'blue',
                                'delivered' => 'sky',
                                'read' => 'emerald',
                                'failed' => 'red',
                                default => 'zinc',
                            }">
                                {{ $message->status?->label() ?? __('Pending') }}
                            </flux:badge>

                            @if ($canUpdate && $message->status?->value === 'failed' && $message->resend_message_id === null)
                                <flux:button size="sm" wire:click="resendMessage('{{ $message->id }}')" wire:loading.attr="disabled">{{ __('Resend') }}</flux:button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </flux:card>
@endif
