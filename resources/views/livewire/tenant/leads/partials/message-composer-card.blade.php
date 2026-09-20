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
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </flux:card>
@endif
