<div wire:init="completeInitialLoad">
    <div class="flex flex-1 flex-col gap-6">
        @php
            // No filters on this page's table — completeInitialLoad()
            // (fired once via wire:init above) is the whole target list.
            // See resources/views/livewire/tenant/leads.blade.php's
            // identical $showSkeletonByDefault for why the skeleton's/real
            // content's STATIC class (not just their wire:loading toggle)
            // has to be conditional on server-known state.
            $listLoadingTargets = 'completeInitialLoad';
            $showSkeletonByDefault = ! $initialLoadComplete;
        @endphp

        <x-breadcrumbs :items="[
            ['label' => __('Dashboard'), 'href' => route('admin.dashboard')],
            ['label' => __('Clients'), 'href' => route('admin.clients.index')],
            ['label' => $tenant->name, 'href' => route('admin.clients.show', $tenant)],
            ['label' => __('Lead Sources')],
        ]" />

        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl" class="hidden lg:block">{{ __('Lead Sources') }}</flux:heading>
                <flux:text>{{ $tenant->name }}</flux:text>
            </div>
            <div class="flex gap-2">
                <flux:button :href="route('admin.clients.show', $tenant)" wire:navigate>{{ __('Back to Client') }}</flux:button>
                <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New Source') }}</flux:button>
            </div>
        </div>

        <flux:card class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('Lead Pipeline') }}</flux:heading>
                <flux:text variant="subtle">{{ __('Click a status to add it to this client\'s pipeline — the click order becomes its position. Click a selected status again to remove it (you\'ll be asked to confirm). A status with leads in it (shown below its name) can\'t be removed until those leads are reassigned.') }}</flux:text>
            </div>

            <div class="flex flex-wrap gap-2">
                @foreach ($this->allLeadStatuses as $status)
                    @php
                        $position = $this->selectedLeadStatuses->search(fn ($selected) => $selected->id === $status->id);
                        $leadCount = $this->leadCountsByStatus[$status->id] ?? 0;
                    @endphp
                    @if ($position !== false)
                        {{-- Selected: removing it is a mutating, hard-to-undo-cleanly
                        action (re-adding appends at the end rather than restoring the
                        original order), so it's the only branch that confirms —
                        confirmRemoveStatus() opens the shared removal modal below,
                        the add case below just toggles immediately. --}}
                        <flux:button
                            size="sm"
                            variant="primary"
                            :color="$status->color"
                            wire:click="confirmRemoveStatus('{{ $status->id }}')"
                        >
                            <span class="mr-1 font-semibold">{{ $position + 1 }}.</span>
                            {{ $status->name }}
                            @if ($leadCount > 0)
                                <span class="ml-1 text-xs opacity-75">({{ trans_choice(':count lead|:count leads', $leadCount, ['count' => $leadCount]) }})</span>
                            @endif
                        </flux:button>
                    @else
                        <flux:button
                            size="sm"
                            variant="ghost"
                            wire:click="toggleLeadStatus('{{ $status->id }}')"
                        >
                            {{ $status->name }}
                        </flux:button>
                    @endif
                @endforeach
            </div>

            @if ($this->selectedLeadStatuses->isNotEmpty())
                <div class="flex flex-col gap-1">
                    <flux:text size="sm" variant="strong">{{ __('Resulting pipeline order') }}</flux:text>
                    <flux:text size="sm" variant="subtle">
                        {{ $this->selectedLeadStatuses->map(fn ($status) => $status->name)->implode(' → ') }}
                    </flux:text>
                </div>
            @endif
        </flux:card>

        <flux:card class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('Preferred Currencies') }}</flux:heading>
                <flux:text variant="subtle">{{ __('Which currencies this client can pick from for a lead\'s Est. Budget / Deal Value. At least one must stay enabled — a client with none can\'t have a new Lead Source created, and existing leads would have no currency to offer.') }}</flux:text>
            </div>

            <div class="flex flex-wrap gap-4">
                @foreach ($this->allCurrencies as $currency)
                    @php $enabled = $this->selectedCurrencies->contains('id', $currency->id); @endphp
                    <flux:checkbox
                        :checked="$enabled"
                        :label="$currency->label()"
                        wire:click="toggleCurrency('{{ $currency->id }}')"
                    />
                @endforeach
            </div>
        </flux:card>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Webhook URL') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->leadSources as $source)
                    <flux:table.row wire:key="lead-source-{{ $source->id }}" wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" @class(['!hidden' => $showSkeletonByDefault])>
                        <flux:table.cell>{{ $source->name }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" icon="{{ $source->type->icon() }}">{{ $source->type->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>
                            @if ($url = $source->webhookUrl())
                                <flux:text class="font-mono text-xs break-all">{{ $url }}</flux:text>
                                {{-- Website Form is the only type authenticated by a
                                per-source secret an admin actually needs to copy out
                                and hand to the client's developer — every other
                                webhook type's own secret (app_secret/verify_token/
                                access_token) is entered BY the admin in the Edit
                                modal, not generated here, so there's nothing to
                                surface on this side for those. readonly+copyable is
                                Flux's built-in <flux:input> pair (see
                                resources/views/flux/input/copyable.blade.php),
                                already used elsewhere in this app's own Flux
                                install — no bespoke clipboard JS needed. --}}
                                @if ($source->type === \App\Enums\LeadSourceType::WebsiteForm)
                                    <div class="mt-1 flex items-center gap-1.5">
                                        <flux:text size="xs" variant="subtle" class="shrink-0">{{ __('API Key:') }}</flux:text>
                                        <flux:input :value="$source->config['api_key'] ?? ''" readonly copyable size="sm" class="font-mono" />
                                    </div>
                                @endif

                                {{-- F1/F4 remediation (forensic audit Batch A) — only
                                rendered for the four Meta-webhook types
                                (LeadSource::META_WEBHOOK_TYPES). $url above already
                                reflects whichever routing shape this source
                                currently has (token-bearing once generated, legacy
                                tenant_code-only until then — see
                                LeadSource::webhookUrl()'s own docblock), so this
                                block only adds the two things that URL alone can't
                                convey: the token action, and the app_secret
                                warning, which are deliberately independent
                                properties of the same source and must never be
                                conflated as "one implies the other". --}}
                                @if (in_array($source->type, \App\Models\LeadSource::META_WEBHOOK_TYPES, true))
                                    <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                        @if ($source->webhook_token_hash === null)
                                            <flux:button size="xs" wire:click="generateWebhookToken('{{ $source->id }}')">{{ __('Generate secure webhook URL') }}</flux:button>
                                        @else
                                            <flux:button size="xs" wire:click="confirmRegenerateWebhookToken('{{ $source->id }}')">{{ __('Regenerate token') }}</flux:button>
                                        @endif

                                        @if ($this->missingAppSecretWarning($source))
                                            <flux:badge size="sm" color="amber" icon="exclamation-triangle">
                                                {{ __('No App Secret — signature verification is OFF for this source') }}
                                            </flux:badge>
                                        @endif
                                    </div>
                                @endif
                            @else
                                <flux:text class="text-xs">{{ __('Polled every :min min', ['min' => $source->config['fetch_interval_minutes'] ?? 15]) }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge :color="$source->is_active ? 'green' : 'zinc'" size="sm">
                                {{ $source->is_active ? __('Active') : __('Inactive') }}
                            </flux:badge>

                            @if ($source->type === \App\Enums\LeadSourceType::Whatsapp)
                                @php
                                    $callingState = \App\Enums\CallingControlState::for($source);
                                    $enableBlockedReason = $this->callingEnableBlockedReason($source);
                                    $callingDetail = $callingDetails[$source->id] ?? ['restrictions' => [], 'warnings' => []];
                                @endphp
                                <div class="mt-3 flex max-w-xs flex-col gap-1.5" wire:key="calling-controls-{{ $source->id }}">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <flux:text size="xs" variant="strong">{{ __('Calling') }}</flux:text>
                                        <flux:badge :color="$callingState->color()" size="sm">{{ $callingState->label() }}</flux:badge>
                                    </div>
                                    <flux:text size="xs" variant="subtle">
                                        {{ __('This app: :local', ['local' => $source->calling_enabled ? __('on') : __('off')]) }}
                                        ·
                                        {{ __('WhatsApp: :meta', ['meta' => $source->calling_meta_status ? strtolower($source->calling_meta_status) : __('not checked')]) }}
                                        @if ($source->calling_meta_checked_at)
                                            · {{ __('checked :time', ['time' => $source->calling_meta_checked_at->diffForHumans()]) }}
                                        @endif
                                    </flux:text>
                                    <div class="flex flex-wrap gap-1.5">
                                        <flux:button
                                            size="xs"
                                            wire:click="enableCalling('{{ $source->id }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="enableCalling('{{ $source->id }}')"
                                            :disabled="$enableBlockedReason !== null"
                                        >{{ __('Enable') }}</flux:button>
                                        <flux:button
                                            size="xs"
                                            wire:click="confirmDisableCalling('{{ $source->id }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="confirmDisableCalling('{{ $source->id }}'), disableCallingConfirmed"
                                        >{{ __('Disable') }}</flux:button>
                                        <flux:button
                                            size="xs"
                                            wire:click="checkCalling('{{ $source->id }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="checkCalling('{{ $source->id }}')"
                                        >{{ __('Check') }}</flux:button>
                                    </div>
                                    @if ($enableBlockedReason !== null)
                                        <flux:text size="xs" variant="subtle">{{ $enableBlockedReason }}</flux:text>
                                    @endif
                                    @foreach ($callingDetail['warnings'] as $warning)
                                        <flux:text size="xs" class="text-amber-600 dark:text-amber-400">{{ $warning }}</flux:text>
                                    @endforeach
                                    @foreach ($callingDetail['restrictions'] as $restriction)
                                        <flux:text size="xs" class="text-amber-600 dark:text-amber-400">
                                            {{ $this->restrictionLabel($restriction['type']) }}@if ($restriction['expiration']) {{ __('until :time', ['time' => \Illuminate\Support\Carbon::createFromTimestamp($restriction['expiration'])->toDayDateTimeString()]) }}@endif
                                        </flux:text>
                                    @endforeach
                                </div>
                            @endif

                            @if ($source->type === \App\Enums\LeadSourceType::FacebookMessenger)
                                @php
                                    $messengerTemplate = $this->messengerTemplatesBySource[$source->id] ?? null;
                                @endphp
                                <div class="mt-3 flex max-w-xs flex-col gap-1.5" wire:key="messenger-templates-{{ $source->id }}">
                                    <flux:text size="xs" variant="strong">{{ __('Template') }}</flux:text>

                                    @if ($messengerTemplate)
                                        {{-- At most one per source (DB-enforced) — the pill
                                        is just the name; click it to see the full details
                                        (language/body/variable count) and Delete Template in
                                        a modal, rather than cluttering every row with the
                                        whole card inline. Approved content is permanently
                                        fixed on Meta's side (confirmed with Meta — no edit
                                        endpoint exists), so there's no "Edit", only "Delete"
                                        to make way for a replacement. --}}
                                        <div>
                                            {{-- flux:badge has no precedent elsewhere in this
                                            app for taking wire:click directly (it renders as
                                            a plain <div> unless given as="button", and
                                            whether its attribute bag reliably forwards an
                                            arbitrary wire:click wasn't something to bet a
                                            modal open on) — wrap it in a real <button> instead,
                                            the same element every other click handler in this
                                            file already uses. --}}
                                            <button type="button" wire:click="viewTemplate('{{ $source->id }}')" class="cursor-pointer">
                                                <flux:badge color="emerald" size="sm">{{ $messengerTemplate->name }}</flux:badge>
                                            </button>
                                        </div>
                                    @else
                                        <div>
                                            <flux:button size="xs" wire:click="createTemplate('{{ $source->id }}')">{{ __('Create Template') }}</flux:button>
                                        </div>
                                    @endif
                                </div>
                            @endif

                            @if ($source->type === \App\Enums\LeadSourceType::Whatsapp)
                                {{-- No local record of whether this template exists or
                                what Meta's review decided — WhatsApp templates are
                                never tracked here (see WhatsAppMessageTemplate's own
                                docblock), unlike Messenger's pill above. Always
                                offered; re-clicking after it's already Approved just
                                surfaces Meta's own "already exists" error. --}}
                                <div class="mt-3 flex max-w-xs items-center gap-2" wire:key="call-permission-template-{{ $source->id }}">
                                    <flux:tooltip content="{{ __('Creates the one WhatsApp template Outbound Calling needs to request permission to call a lead. Submits to Meta for review — approval usually completes within a few hours.') }}">
                                        <flux:button size="xs" wire:click="createCallPermissionTemplate('{{ $source->id }}')">{{ __('Create Call Permission Template') }}</flux:button>
                                    </flux:tooltip>
                                    <flux:tooltip content="{{ __('Deletes it from Meta so a replacement with different wording can be created — breaks Outbound Calling permission requests on this source until a replacement is approved.') }}">
                                        <flux:button size="xs" variant="danger" icon="trash" wire:click="confirmDeleteCallPermissionTemplate('{{ $source->id }}')" :aria-label="__('Delete call permission template')" />
                                    </flux:tooltip>
                                </div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" wire:click="edit('{{ $source->id }}')">{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" wire:click="testConnection('{{ $source->id }}')">{{ __('Test') }}</flux:button>
                                <flux:button size="sm" wire:click="toggleActive('{{ $source->id }}')">
                                    {{ $source->is_active ? __('Deactivate') : __('Activate') }}
                                </flux:button>
                                <flux:button size="sm" variant="danger" wire:click="confirmDelete('{{ $source->id }}')">
                                    {{ __('Delete') }}
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" @class(['!hidden' => $showSkeletonByDefault])>
                        <flux:table.cell colspan="5">{{ __('No lead sources configured yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>

            {{-- Skeleton: a second <tbody>, hidden by default and revealed
            only while loading — see leads.blade.php for why this can't be a
            bare wire:loading directive. Row count is arbitrary (3), this
            list is never paginated. --}}
            <flux:table.rows @class(['!hidden' => ! $showSkeletonByDefault]) wire:loading.remove.class="!hidden" wire:target="{{ $listLoadingTargets }}">
                @for ($i = 0; $i < 3; $i++)
                    <flux:table.row wire:key="lead-source-skeleton-{{ $i }}">
                        <flux:table.cell><div class="h-3 w-24 skeleton-shimmer rounded"></div></flux:table.cell>
                        <flux:table.cell><div class="h-5 w-20 skeleton-shimmer rounded-full"></div></flux:table.cell>
                        <flux:table.cell><div class="h-3 w-32 skeleton-shimmer rounded"></div></flux:table.cell>
                        <flux:table.cell><div class="h-5 w-16 skeleton-shimmer rounded-full"></div></flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end">
                                <div class="h-6 w-32 skeleton-shimmer rounded"></div>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endfor
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal wire:model.self="showModal" scroll="body" class="w-full md:max-w-[36rem]">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit Lead Source') : __('New Lead Source') }}</flux:heading>

            @if ($editingId)
                <flux:text>{{ __('Type') }}: {{ $this->selectedType?->label() }}</flux:text>
            @else
                @php
                    $sourceTypeOpts = array_merge(
                        [['value' => '', 'label' => __('Select a type')]],
                        collect($this->typeOptions)->map(fn($o) => ['value' => $o->value, 'label' => $o->label()])->toArray()
                    );
                @endphp
                <x-dropdown-select wire:model.live="type" :label="__('Type')" :placeholder="__('Select a type')" :options="$sourceTypeOpts" />
            @endif

            <flux:input wire:model="name" :label="__('Name')" required />

            @if ($this->selectedType)
                @foreach ($this->selectedType->configFields() as $field)
                    @if ($field['type'] === 'select')
                        @php
                            $fieldOpts = array_merge(
                                [['value' => '', 'label' => __('Select an option')]],
                                collect($field['options'])->map(fn($lbl, $val) => ['value' => (string) $val, 'label' => (string) $lbl])->values()->toArray()
                            );
                        @endphp
                        <x-dropdown-select wire:key="config-field-{{ $field['key'] }}" wire:model.live="config.{{ $field['key'] }}" :label="$field['label']" :options="$fieldOpts" />
                    @else
                        <flux:input
                            wire:key="config-field-{{ $field['key'] }}"
                            wire:model="config.{{ $field['key'] }}"
                            :label="$field['label']"
                            type="{{ $field['type'] === 'password' ? 'password' : ($field['type'] === 'number' ? 'number' : ($field['type'] === 'email' ? 'email' : 'text')) }}"
                            :placeholder="$editingId && $field['sensitive'] ? __('Leave blank to keep current value') : ''"
                            :viewable="$field['sensitive']"
                            :disabled="isset($field['depends_on']) && empty($config[$field['depends_on']] ?? null)"
                        />
                    @endif
                @endforeach
            @endif

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-confirm-modal
        wire:model.self="showDeleteModal"
        :title="__('Delete this lead source?')"
        confirm-action="deleteConfirmed"
        :confirm-label="__('Delete')"
    />

    <x-confirm-modal
        wire:model.self="showDisableCallingModal"
        :title="__('Turn calling off for this WhatsApp number?')"
        :description="__('Customers will no longer be able to call this number through WhatsApp. You can turn it back on later.')"
        confirm-action="disableCallingConfirmed"
        :confirm-label="__('Disable calling')"
    />

    <flux:modal wire:model.self="showCreateTemplateModal" :dismissible="false" class="md:w-[32rem]">
        <form wire:submit="saveTemplate" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Create Messenger Template') }}</flux:heading>
            <flux:text variant="subtle">
                {{ __('Submitted directly to Meta for approval — a Utility Message Template, used to re-engage a lead once their 24-hour reply window has closed.') }}
            </flux:text>

            <flux:input
                wire:model="templateName"
                :label="__('Template Name')"
                :description="__('Lowercase letters, numbers, underscores only. Must be unique on this Page.')"
                placeholder="followup_after_24h"
                required
            />

            <flux:input wire:model="templateLanguage" :label="__('Language Code')" placeholder="en_US" required />

            {{-- Body text: a plain textarea the admin writes normally, with
            an "Insert Variable" button that drops the next placeholder in
            at the cursor — never needs to know or type Meta's {{n}}
            syntax. $wire.entangle(name) keeps `body` instantly reactive for
            the client-only preview/count below without a network
            round-trip per keystroke, syncing back to the real Livewire
            property only when the form actually submits — this Livewire
            version's entangle() defaults to non-live (see
            generateEntangleFunction in vendor/livewire/livewire/dist/
            livewire.js), so no modifier is needed for that; there is no
            `.defer` modifier in this version at all — chaining it reads a
            nonexistent property and silently returns undefined, which was
            a real bug here (the body text field always submitted empty,
            no matter what was typed). --}}
            <div
                x-data="{
                    body: $wire.entangle('templateBodyText'),
                    get variableNumbers() {
                        return [...new Set([...this.body.matchAll(/\{\{(\d+)\}\}/g)].map((m) => parseInt(m[1], 10)))].sort((a, b) => a - b);
                    },
                    insertVariable() {
                        const el = $refs.templateBody;
                        const start = el.selectionStart;
                        const end = el.selectionEnd;
                        const next = this.variableNumbers.length ? Math.max(...this.variableNumbers) + 1 : 1;
                        // Built from single-brace pieces, never a literal
                        // adjacent brace pair in this file's own source.
                        const openBraces = '{' + '{';
                        const closeBraces = '}' + '}';
                        const token = openBraces + next + closeBraces;
                        this.body = this.body.slice(0, start) + token + this.body.slice(end);
                        $nextTick(() => { el.focus(); el.selectionStart = el.selectionEnd = start + token.length; });
                    },
                }"
                class="flex flex-col gap-2"
            >
                {{-- A wording Meta's own reviewer has actually approved —
                see LeadSources::EXAMPLE_TEMPLATE_BODY's own docblock for
                why this is a PHP constant rather than written inline here:
                it contains a literal, adjacent "{ {" pair, which this
                file's own body-text field has already been bitten by once
                (see that field's docblock below). --}}
                <div class="flex flex-col gap-1.5 rounded-lg border border-blue-200/80 bg-blue-50 p-3 dark:border-blue-900/60 dark:bg-blue-950/40">
                    <flux:text size="xs" variant="strong" class="text-blue-700 dark:text-blue-300">{{ __('Example Meta has approved') }}</flux:text>
                    <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $this::EXAMPLE_TEMPLATE_BODY }}</p>
                    <flux:text size="xs" variant="subtle">
                        {{ __('Meta\'s Utility category needs a status update about something the customer already has with you — avoid words like "chat," "continue our conversation," or "offer," which read as an invitation rather than an update.') }}
                    </flux:text>
                    <div>
                        {{-- A plain wire:click round-trip, not an Alpine
                        assignment — $wire.entangle('templateBodyText')
                        above is bidirectional, so once this sets the real
                        Livewire property, the textarea's local `body`
                        updates on its own; no extra glue needed. --}}
                        <flux:button type="button" size="xs" wire:click="useExampleTemplateBody">{{ __('Use this example') }}</flux:button>
                    </div>
                </div>

                <flux:field>
                    <flux:label>{{ __('Message') }}</flux:label>
                    <textarea
                        x-ref="templateBody"
                        x-model="body"
                        rows="4"
                        placeholder="{{ __('Write the message normally, e.g. Hi there, following up on your inquiry.') }}"
                        class="w-full rounded-lg border border-zinc-300/80 bg-zinc-100/90 px-3 py-1.5 text-sm text-zinc-800 shadow-xs focus:outline-none focus:ring-2 focus:ring-accent/40 dark:border-zinc-700/80 dark:bg-zinc-900/90 dark:text-zinc-200"
                    ></textarea>
                    <flux:description>
                        {{ __('Click "Insert Variable" wherever you want something that changes per lead (like their name) to go — no placeholders at all is also fine.') }}
                    </flux:description>
                </flux:field>

                <div class="flex items-center justify-between gap-2">
                    <flux:button type="button" size="xs" icon="plus" x-on:click="insertVariable()">{{ __('Insert Variable') }}</flux:button>
                    <flux:text size="xs" variant="subtle" x-text="variableNumbers.length === 1 ? '1 variable' : variableNumbers.length + ' variables'"></flux:text>
                </div>

                <div x-show="body.length > 0" x-cloak class="rounded-lg border border-zinc-200/80 bg-zinc-50 p-3 dark:border-zinc-700/80 dark:bg-zinc-900/60">
                    <flux:text size="xs" variant="subtle">{{ __('Preview') }}</flux:text>
                    {{-- Plain "Example" rather than @js(__('Example')) — @js()
                    emits JSON (double-quoted) output, which would close
                    this x-text="..." HTML attribute early the exact same
                    way a stray '"' in a comment did elsewhere in this
                    block. Platform-Admin-only internal tooling; not worth
                    the same risk for one word. --}}
                    <p class="text-sm text-zinc-700 dark:text-zinc-300" x-text="body.replace(/\{\{(\d+)\}\}/g, (match, n) => '[Example ' + n + ']')"></p>
                </div>
            </div>
            @error('templateBodyText') <flux:text size="sm" class="text-red-600 dark:text-red-400">{{ $message }}</flux:text> @enderror

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showCreateTemplateModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveTemplate">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Opened by clicking a Messenger row's template pill — the pill
    itself only ever shows the name, this is the one place the full
    registered template (language/body/variable count) is visible.
    closable defaults true on flux:modal (see vendor/livewire/flux's own
    modal/index.blade.php), so the corner X close button is already there
    for free; the explicit "Close" button below is just a second, equally
    obvious way out. --}}
    <flux:modal wire:model.self="showTemplateDetailsModal" class="md:w-[28rem]">
        @php
            $viewingTemplate = $this->messengerTemplatesBySource[$viewingTemplateSourceId] ?? null;
        @endphp
        @if ($viewingTemplate)
            <div class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('Messenger Template') }}</flux:heading>

                <div class="flex flex-col gap-2 rounded-lg border border-zinc-200/80 bg-zinc-50 p-3 dark:border-zinc-700/80 dark:bg-zinc-900/60">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <flux:badge color="emerald" size="sm">{{ $viewingTemplate->name }}</flux:badge>
                        <flux:text size="xs" variant="subtle">{{ $viewingTemplate->language }}</flux:text>
                    </div>
                    @if ($viewingTemplate->body_text)
                        <flux:text size="sm">{{ $viewingTemplate->body_text }}</flux:text>
                    @endif
                    <flux:text size="xs" variant="subtle">
                        {{ trans_choice(':count variable|:count variables', $viewingTemplate->variable_count, ['count' => $viewingTemplate->variable_count]) }}
                    </flux:text>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="$set('showTemplateDetailsModal', false)">{{ __('Close') }}</flux:button>
                    <flux:button type="button" variant="danger" wire:click="confirmDeleteTemplate('{{ $viewingTemplateSourceId }}')">{{ __('Delete Template') }}</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    <x-confirm-modal
        wire:model.self="showDeleteTemplateModal"
        :title="__('Delete this template?')"
        :description="__('This removes it from Meta as well — it can no longer be used to re-engage any lead on this source. You can create a replacement right after.')"
        confirm-action="deleteTemplateConfirmed"
        :confirm-label="__('Delete')"
    />

    <x-confirm-modal
        wire:model.self="showDeleteCallPermissionTemplateModal"
        :title="__('Delete the call permission template?')"
        :description="__('This removes it from Meta as well — Outbound Calling permission requests on this source will fail until a replacement is created and approved.')"
        confirm-action="deleteCallPermissionTemplateConfirmed"
        :confirm-label="__('Delete')"
    />

    <x-confirm-modal
        wire:model.self="showRemoveStatusModal"
        :title="__('Remove :name from this client\'s pipeline?', ['name' => $this->allLeadStatuses->firstWhere('id', $confirmingRemoveStatusId)?->name ?? ''])"
        confirm-action="removeStatusConfirmed"
        :confirm-label="__('Remove')"
    />

    <x-confirm-modal
        wire:model.self="showRegenerateTokenModal"
        :title="__('Regenerate this source\'s webhook token?')"
        :description="__('The current webhook URL will stop working immediately — update it at Meta before regenerating, or inbound webhooks for this source will fail until you do.')"
        confirm-action="regenerateWebhookTokenConfirmed"
        :confirm-label="__('Regenerate')"
    />

    {{-- F1 remediation (forensic audit Batch A) — the one-time reveal for a
    just-(re)generated webhook token, mirroring
    resources/views/livewire/tenant/website-forms.blade.php's own
    showTokenModal exactly: not dismissible by clicking outside, since this
    is the only moment the plaintext is ever shown again. --}}
    <flux:modal wire:model.self="showTokenModal" :dismissible="false" class="md:w-[36rem]">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Webhook URL for :name', ['name' => $revealedTokenSourceName]) }}</flux:heading>
            <flux:text variant="subtle">
                {{ __('Copy this URL now — the token in it will not be shown again. Paste it into Meta\'s App Dashboard as this source\'s callback URL.') }}
            </flux:text>
            <flux:input readonly value="{{ $revealedWebhookUrl }}" copyable class="font-mono text-xs" />
            <div>
                <flux:text size="xs" variant="subtle">{{ __('Token only:') }}</flux:text>
                <flux:input readonly value="{{ $revealedToken }}" copyable size="sm" class="font-mono" />
            </div>
            <div class="flex justify-end">
                <flux:button variant="primary" wire:click="$set('showTokenModal', false)">{{ __('Done') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
