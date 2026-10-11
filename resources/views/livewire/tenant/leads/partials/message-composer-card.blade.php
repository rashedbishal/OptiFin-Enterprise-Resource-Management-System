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

    <flux:card class="h-full max-h-[34rem] flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg">{{ __(':channel Reply', ['channel' => $channel->label()]) }}</flux:heading>

            @if ($this->canDeleteConversation)
                <flux:button type="button" size="xs" variant="ghost" icon="trash" wire:click="confirmDeleteConversation">{{ __('Delete conversation') }}</flux:button>
            @endif
        </div>

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

                    {{-- x-model/x-model on the generated fields below only
                    works correctly once $wire.entangle() values are
                    genuinely reactive end to end, which is why this whole
                    block (picker included) stays inside one x-data scope
                    rather than mixing a PHP-rendered picker with an
                    Alpine-rendered field list — see the body-text field in
                    Platform\Clients\LeadSources' Create Template modal for
                    the same "no literal adjacent { { in this file's own
                    source" discipline the helper methods below follow. --}}
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

                            {{-- Only ever non-null for a Messenger template
                            (see $templatesPayload's own comment above) — a
                            live, friendly stand-in for "Variable 1/Variable
                            2", showing exactly where in the real message
                            each value lands as it's typed. --}}
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

        @if ($this->outboundMessages->isNotEmpty())
            <flux:separator />

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
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
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
