{{-- Full-page-only (Overview tab, show.blade.php — never show-panel.blade.php's
flyout): the Sent/Failed/Resend log that used to sit at the bottom of
message-composer-card.blade.php, carried over verbatim into its own card
now that the composer merged into messenger-chat-card.blade.php (see that
file's own docblock). The one new thing here is the X button, Client
Admin only — it hides that one row from THIS list only (Show::
hideOutboundMessage()'s own docblock): the underlying message and its
bubble in the main conversation thread are never touched. Everything
else — the Resend button/wire:click, the status badges, the media
link — is unchanged. --}}
@if ($this->messagingChannel)
    @php
        $canUpdate = $this->canUpdate;
        $canDelete = $this->canDelete;
    @endphp

    @if ($this->outboundMessages->isNotEmpty())
        <flux:card class="flex h-full max-h-[34rem] flex-col gap-3">
            <flux:heading size="lg">{{ __('Activity Log') }}</flux:heading>

            <div class="min-h-0 flex flex-1 flex-col gap-2 overflow-y-auto overscroll-contain pe-1">
                @foreach ($this->outboundMessages as $message)
                    <div class="flex items-start justify-between gap-2" wire:key="outbound-message-{{ $message->id }}">
                        <div>
                            @if ($message->hasMedia())
                                <a href="{{ route('tenant-leads.message-attachment', [$this->lead, $message]) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-sm text-blue-600 hover:underline dark:text-blue-400">
                                    <flux:icon :icon="$message->message_type === \App\Enums\MessageType::Image ? 'photo' : 'document-text'" class="size-4" />
                                    {{ $message->attachment_name }}
                                </a>
                            @endif
                            @if (filled($message->body))
                                <flux:text>{{ $message->body }}</flux:text>
                            @endif
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

                            @if ($canDelete)
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="x-mark"
                                    :aria-label="__('Remove this entry from the activity log')"
                                    wire:click="confirmHideOutboundMessage('{{ $message->id }}')"
                                />
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </flux:card>
    @endif

    @if ($this->showHideOutboundMessageModal && $this->canDelete)
        <x-confirm-modal
            wire:model.self="showHideOutboundMessageModal"
            :title="__('Remove this entry from the activity log?')"
            :description="__('This only removes it from this list — the message itself stays in the conversation above, untouched.')"
            confirm-action="hideOutboundMessageConfirmed"
            :confirm-label="__('Remove')"
        />
    @endif
@endif
