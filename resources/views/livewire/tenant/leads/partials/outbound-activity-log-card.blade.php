{{-- Full-page-only (Overview tab, show.blade.php — never show-panel.blade.php's
flyout): the Sent/Failed/Resend log that used to sit at the bottom of
message-composer-card.blade.php, carried over verbatim into its own card
now that the composer merged into messenger-chat-card.blade.php (see that
file's own docblock). The one new thing on the log rows is the X button,
Client Admin only — it hides that one row from THIS list only (Show::
hideOutboundMessage()'s own docblock): the underlying message and its
bubble in the main conversation thread are never touched. Everything
else on a row — the Resend button/wire:click, the status badges, the
media link — is unchanged.

Also carries the template-send form (once the reply window has closed) —
moved here from messenger-chat-card.blade.php at the user's request: that
form's own height was squeezing the chat card's conversation thread down
to almost nothing in its fixed max-h card. Markup/Alpine/wire: bindings
are unchanged, only its location. This is why this card can no longer be
gated purely on "has any outbound messages" — a brand-new conversation
whose window closed instantly (no messages sent yet at all) still needs
this card for the template form alone. --}}
@if ($this->messagingChannel)
    @php
        $channel = $this->messagingChannel;
        $canUpdate = $this->canUpdate;
        $canDelete = $this->canDelete;
        $eligibility = $this->messagingEligibility;
        $showTemplateSection = $canUpdate && ! ($eligibility && $eligibility->eligible);
        $hasLogEntries = $this->outboundMessages->isNotEmpty();
    @endphp

    @if ($showTemplateSection || $hasLogEntries)
        <flux:card class="flex h-full max-h-[34rem] flex-col gap-3">
            <flux:heading size="lg">{{ __('Activity Log') }}</flux:heading>

            @if ($showTemplateSection)
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

            @if ($showTemplateSection && $hasLogEntries)
                <flux:separator />
            @endif

            @if ($hasLogEntries)
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
            @endif
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
