<div class="flex flex-1 flex-col gap-6">
    {{-- Sticky header: breadcrumbs, heading, actions, and filters stay
    pinned in view while the board beneath scrolls both vertically (the
    page) and horizontally (the board's own scrollbox below). The negative
    top margin/matching top padding cancel out flux:main's own top inset,
    so this sits flush against the app's fixed top bar instead of leaving
    a gap above it — both at rest and once stuck. Sized down below lg
    since the heading/actions/filters otherwise eat too much of a
    tablet/mobile viewport before any leads are visible. Forced onto its
    own compositor layer ([transform:translateZ(0)]) — without it, some
    browsers/devtools mobile emulation can paint scrolled-past content
    through this element for a frame instead of it staying solid. --}}
    <div class="sticky top-0 z-20 -mx-4 flex [transform:translateZ(0)] flex-col gap-2 bg-white px-4 py-3 lg:-mx-8 lg:gap-3.5 lg:px-8 lg:py-4 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
        <x-breadcrumbs class="hidden sm:flex" :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard')],
            ['label' => __('Leads')],
        ]" />

        <div class="flex items-center justify-between gap-2">
            <flux:heading size="lg" class="text-base sm:text-lg lg:text-2xl font-semibold">{{ __('Leads') }}</flux:heading>
            <div class="flex items-center gap-1.5 sm:gap-2">
                @if ($this->canCreateLead)
                    <flux:button variant="primary" icon="plus" size="sm" wire:click="createLead">{{ __('New Lead') }}</flux:button>
                @endif
                <flux:button icon="arrow-down-tray" size="sm" wire:click="export">{{ __('Export CSV') }}</flux:button>
            </div>
        </div>

        @php
            $priorityOptionsFormatted = array_merge(
                [['value' => '', 'label' => __('All priorities')]],
                collect($this->priorityOptions())->map(fn($opt) => ['value' => $opt, 'label' => ucfirst($opt)])->toArray()
            );
            $sourceTypeOptionsFormatted = array_merge(
                [['value' => '', 'label' => __('All sources')]],
                collect($this->sourceTypeOptions())->map(fn($opt) => ['value' => $opt, 'label' => ucfirst(str_replace('_', ' ', $opt))])->toArray()
            );
            if ($this->isClientAdmin) {
                $assignedToOptionsFormatted = array_merge(
                    [
                        ['value' => '', 'label' => __('All users')],
                        ['value' => 'unassigned', 'label' => __('Unassigned')],
                    ],
                    collect($this->assignableUsers)->map(fn($u) => ['value' => (string) $u->id, 'label' => $u->name])->toArray()
                );
            }
        @endphp

        <div class="grid grid-cols-2 gap-2 md:grid-cols-3 lg:gap-3">
            <x-dropdown-select wire:model.live="priority" :placeholder="__('All priorities')" :options="$priorityOptionsFormatted" icon="funnel" />
            <x-dropdown-select wire:model.live="sourceType" :placeholder="__('All sources')" :options="$sourceTypeOptionsFormatted" icon="tag" />

            @if ($this->isClientAdmin)
                <x-dropdown-select wire:model.live="assignedTo" :placeholder="__('All users')" :options="$assignedToOptionsFormatted" icon="user" />
            @endif
        </div>
    </div>

    @if ($this->facebookLeadAdsSources->isNotEmpty())
        <div class="flex flex-col gap-2">
            <flux:text variant="strong" size="sm">{{ __('Facebook Lead Ads') }}</flux:text>
            <div class="flex flex-wrap gap-3">
                @foreach ($this->facebookLeadAdsSources as $source)
                    <div wire:key="fb-source-{{ $source->id }}" class="flex w-full items-center justify-between gap-3 rounded-lg border border-zinc-200 bg-white p-3 sm:w-72 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="min-w-0">
                            <flux:text variant="strong" class="block truncate">{{ $source->name }}</flux:text>
                            <flux:text size="sm" variant="subtle">
                                {{ trans_choice(':count lead|:count leads', $source->leads_count, ['count' => $source->leads_count]) }}
                                @if ($source->leads_max_created_at)
                                    &middot; {{ \Illuminate\Support\Carbon::parse($source->leads_max_created_at)->diffForHumans() }}
                                @endif
                            </flux:text>
                        </div>
                        @if ($source->leads_count > 0)
                            <flux:button size="sm" :href="route('tenant-leads.source', $source)" wire:navigate>{{ __('View All') }}</flux:button>
                        @elseif ($this->isClientAdmin)
                            <flux:button size="sm" variant="danger" wire:click="deleteSource('{{ $source->id }}')" wire:confirm="{{ __('Delete this lead source?') }}">
                                {{ __('Delete') }}
                            </flux:button>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($this->boardLeads->isEmpty() && $this->facebookLeadAdsSources->isEmpty())
        <div class="flex flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-zinc-200 py-16 text-center dark:border-zinc-700">
            <flux:icon.inbox class="size-8 text-zinc-300 dark:text-zinc-600" />
            <flux:text variant="strong">{{ __('No leads yet') }}</flux:text>
            <flux:text size="sm" variant="subtle">{{ __('New leads from your connected channels will show up here.') }}</flux:text>
        </div>
    @else
        <div wire:loading.class.delay="opacity-70" wire:target="moveLead">
            {{-- Desktop / tablet: Kanban board, one column per pipeline status.
            Deliberately no max-height here: columns grow to their natural
            content height, so the board never crops off cards at a fixed
            box height. Horizontal overflow is scoped to this row itself
            (rather than bubbling up to the whole page) so the sticky
            header above stays put no matter which way this scrolls. --}}
            <div
                class="hidden overflow-x-auto lg:flex lg:items-start lg:gap-4"
                x-data="{
                    scrollEl: null,
                    edge: 220,
                    maxSpeed: 9,
                    lastClientX: null,
                    rafId: null,
                    isDragging: false,
                    init() {
                        this.scrollEl = this.$el;
                    },
                    handleDrag(event) {
                        if (event && typeof event.clientX === 'number' && event.clientX !== 0) {
                            this.lastClientX = event.clientX;
                        }
                        if (! this.isDragging) {
                            this.isDragging = true;
                            // The browser's own native drag-autoscroll can also target
                            // this element and stack on top of ours near an edge,
                            // producing a sudden extra burst of speed. Dropping
                            // overflow disqualifies it as a native autoscroll target
                            // for the duration of the drag; our own scrollLeft writes
                            // below still move the content either way.
                            this.scrollEl?.style.setProperty('overflow', 'hidden');
                        }
                        if (! this.rafId) {
                            this.rafId = requestAnimationFrame(() => this.doScroll());
                        }
                    },
                    stopDrag() {
                        this.isDragging = false;
                        this.lastClientX = null;
                        this.scrollEl?.style.removeProperty('overflow');
                        if (this.rafId) {
                            cancelAnimationFrame(this.rafId);
                            this.rafId = null;
                        }
                    },
                    doScroll() {
                        if (! this.isDragging || ! this.scrollEl || this.lastClientX === null) {
                            this.rafId = null;
                            return;
                        }
                        const rect = this.scrollEl.getBoundingClientRect();
                        const clientX = this.lastClientX;
                        const leftThreshold = rect.left + this.edge;
                        const rightThreshold = rect.right - this.edge;

                        // Ease-in (quadratic) so scrolling ramps up gently near the
                        // edge instead of jumping straight to near-max speed.
                        if (clientX < leftThreshold) {
                            // Pointer is near the left edge of main content, over the sidebar, or past the left screen edge
                            const dist = leftThreshold - clientX;
                            const speedRatio = Math.min(1, Math.max(0, dist / this.edge)) ** 2;
                            this.scrollEl.scrollLeft -= this.maxSpeed * speedRatio;
                        } else if (clientX > rightThreshold) {
                            // Pointer is near or past the right edge of main content
                            const dist = clientX - rightThreshold;
                            const speedRatio = Math.min(1, Math.max(0, dist / this.edge)) ** 2;
                            this.scrollEl.scrollLeft += this.maxSpeed * speedRatio;
                        }

                        this.rafId = requestAnimationFrame(() => this.doScroll());
                    },
                }"
                x-on:dragstart.window.capture="handleDrag($event)"
                x-on:dragover.window.capture.prevent="handleDrag($event)"
                x-on:dragend.window.capture="stopDrag()"
                x-on:drop.window.capture.prevent="stopDrag()"
                x-on:mouseleave.window="stopDrag()"
                x-on:mouseup.window="stopDrag()"
            >
                @foreach ($this->tenantLeadStatuses as $statusCase)
                    @php
                        $columnLeads = $this->boardLeads->get($statusCase->id, collect());
                        $totalCount = collect($this->boardLeadCounts)->firstWhere('status', $statusCase->id)['count'] ?? 0;
                    @endphp
                    <div
                        wire:key="column-{{ $statusCase->id }}"
                        x-data="{ dragDepth: 0 }"
                        x-on:dragover.prevent
                        x-on:dragenter.prevent="dragDepth++"
                        x-on:dragleave="dragDepth = Math.max(0, dragDepth - 1)"
                        x-on:drop="dragDepth = 0; $wire.moveLead($event.dataTransfer.getData('text/plain'), '{{ $statusCase->id }}')"
                        {{--
                            dragenter/dragleave fire on every child element boundary
                            too (not just the column's own edge), so a plain toggle
                            flickers the highlight off the moment the pointer passes
                            over a card. Track nesting depth instead: only clear the
                            highlight once it's actually left the column, however
                            many child elements it crossed on the way.
                        --}}
                        :class="dragDepth > 0 ? '{{ $statusCase->columnDropTargetClasses() }}' : '{{ $statusCase->columnBackgroundClasses() }}'"
                        class="flex w-72 min-h-32 shrink-0 flex-col rounded-lg transition-colors"
                    >
                        <div class="flex items-center justify-between gap-2 px-3 py-2.5">
                            <flux:text variant="strong" size="sm">{{ $statusCase->label() }}</flux:text>
                            <flux:badge size="sm" :color="$statusCase->color()">{{ $totalCount }}</flux:badge>
                        </div>

                        <div class="flex flex-1 flex-col gap-2 px-3 pb-3">
                            @forelse ($columnLeads as $lead)
                                @include('livewire.tenant.leads.partials.lead-card', ['lead' => $lead, 'tenantCurrency' => $this->tenantCurrency, 'draggable' => true, 'canMove' => $this->canUpdateLeads, 'tenantStatuses' => $this->tenantLeadStatuses])
                            @empty
                                <div class="rounded-md border border-dashed border-zinc-200 py-6 text-center dark:border-zinc-700">
                                    <flux:text size="sm" variant="subtle">{{ __('No leads') }}</flux:text>
                                </div>
                            @endforelse

                            @if ($columnLeads->count() < $totalCount)
                                <flux:text size="sm" variant="subtle" class="px-1 pt-1">
                                    {{ __('Showing :shown of :total — refine filters to see the rest.', ['shown' => $columnLeads->count(), 'total' => $totalCount]) }}
                                </flux:text>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Mobile: status-grouped vertical list, no drag-and-drop — each
            card's "…" menu is the way to move it between statuses. --}}
            <div class="flex flex-col gap-6 lg:hidden">
                @foreach ($this->tenantLeadStatuses as $statusCase)
                    @php $columnLeads = $this->boardLeads->get($statusCase->id, collect()); @endphp
                    @if ($columnLeads->isNotEmpty())
                        <div wire:key="mobile-group-{{ $statusCase->id }}" class="flex flex-col gap-2">
                            <div class="flex items-center gap-2 px-1">
                                <flux:text variant="strong" size="sm">{{ $statusCase->label() }}</flux:text>
                                <flux:badge size="sm" :color="$statusCase->color()">{{ $columnLeads->count() }}</flux:badge>
                            </div>
                            <div class="flex flex-col gap-2">
                                @foreach ($columnLeads as $lead)
                                    @include('livewire.tenant.leads.partials.lead-card', ['lead' => $lead, 'tenantCurrency' => $this->tenantCurrency, 'draggable' => false, 'canMove' => $this->canUpdateLeads, 'tenantStatuses' => $this->tenantLeadStatuses])
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endif

    @if ($this->canCreateLead)
        <flux:modal wire:model.self="showCreateLeadModal" :dismissible="false" scroll="body" class="w-full md:max-w-[46rem] lg:max-w-[80rem]">
            <form wire:submit="saveLead" class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg">{{ __('New Lead') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Register a lead that came in outside your connected channels — a call, a walk-in, a referral.') }}</flux:text>
                </div>

                <div class="grid gap-x-14 gap-y-6 lg:grid-cols-2">
                    <div class="flex flex-col gap-6">
                        <div class="flex flex-col gap-4">
                            <div class="flex items-center gap-2">
                                <flux:icon.user variant="micro" class="text-zinc-400 dark:text-zinc-500" />
                                <flux:heading size="sm">{{ __('Contact') }}</flux:heading>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <flux:input wire:model="leadName" :label="__('Contact Person')" placeholder="{{ __('e.g. Rafiqul Islam') }}" required />
                                <flux:input wire:model="leadPhone" type="tel" :label="__('Phone / WhatsApp')" placeholder="{{ __('e.g. +880 1712-345678') }}" required />
                                <flux:input wire:model="leadEmail" type="email" :label="__('Email Address')" placeholder="{{ __('e.g. rafiq@apexlogistics.com') }}" class="sm:col-span-2" />
                            </div>
                        </div>

                        <flux:separator />

                        <div class="flex flex-col gap-4">
                            <div class="flex items-center gap-2">
                                <flux:icon.building-office variant="micro" class="text-zinc-400 dark:text-zinc-500" />
                                <flux:heading size="sm">{{ __('Company') }}</flux:heading>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <flux:input wire:model="leadCompany" :label="__('Company / Organization')" placeholder="{{ __('e.g. Apex Global Logistics Ltd.') }}" />
                                <flux:input wire:model="leadWebsite" type="url" :label="__('Website URL')" placeholder="{{ __('e.g. https://apexlogistics.com') }}" />
                                <flux:input wire:model="leadIndustry" :label="__('Industry Sector')" placeholder="{{ __('e.g. Technology & Digital') }}" />
                                <flux:input wire:model="leadLocation" :label="__('Location / City')" placeholder="{{ __('e.g. Dhaka, Bangladesh') }}" />
                            </div>
                        </div>

                        <flux:separator />

                        <flux:textarea wire:model="leadMessage" :label="__('Intake Notes / Scope Details')" placeholder="{{ __('Key client requirements, current challenges, timeline expectations...') }}" rows="3" />
                    </div>

                    <div class="flex flex-col gap-6">
                        <div class="flex flex-col gap-4">
                            <div class="flex items-center gap-2">
                                <flux:icon.currency-dollar variant="micro" class="text-zinc-400 dark:text-zinc-500" />
                                <flux:heading size="sm">{{ __('Deal') }}</flux:heading>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <flux:input wire:model="leadServiceInterest" :label="__('Service Interested')" placeholder="{{ __('e.g. Custom Web Application & SaaS') }}" class="sm:col-span-2" />

                                <flux:input.group :label="__('Est. Budget')">
                                    <flux:input.group.prefix>{{ $this->tenantCurrency }}</flux:input.group.prefix>
                                    <flux:input wire:model="leadEstimatedBudget" type="number" step="0.01" min="0" placeholder="0.00" />
                                </flux:input.group>

                                <flux:input.group :label="__('Deal Value')">
                                    <flux:input.group.prefix>{{ $this->tenantCurrency }}</flux:input.group.prefix>
                                    <flux:input wire:model="leadDealValue" type="number" step="0.01" min="0" placeholder="0.00" />
                                </flux:input.group>

                                <flux:input.group :label="__('Win Probability')">
                                    <flux:input wire:model="leadWinProbability" type="number" min="0" max="100" placeholder="50" />
                                    <flux:input.group.suffix>%</flux:input.group.suffix>
                                </flux:input.group>

                                <flux:input wire:model="leadNextFollowUpAt" type="date" :label="__('Next Follow-up Date')" />
                            </div>
                        </div>

                        <flux:separator />

                        <div class="flex flex-col gap-4">
                            <div class="flex items-center gap-2">
                                <flux:icon.flag variant="micro" class="text-zinc-400 dark:text-zinc-500" />
                                <flux:heading size="sm">{{ __('Pipeline') }}</flux:heading>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <flux:select wire:model="leadStatus" :label="__('Pipeline Stage')">
                                    @foreach ($this->statusOptions() as $option)
                                        <flux:select.option value="{{ $option->id }}">{{ $option->label() }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:select wire:model="leadPriority" :label="__('Priority')">
                                    @foreach ($this->priorityOptions() as $option)
                                        <flux:select.option value="{{ $option }}">{{ ucfirst($option) }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                @if ($this->isClientAdmin)
                                    <flux:select wire:model="leadAssigneeId" :label="__('Assigned Sales Executive')" placeholder="{{ __('Unassigned') }}" class="sm:col-span-2">
                                        @foreach ($this->assignableUsers as $assignableUser)
                                            <flux:select.option value="{{ $assignableUser->id }}">{{ $assignableUser->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <flux:separator />

                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="$set('showCreateLeadModal', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('Create Lead') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    <flux:modal variant="flyout" wire:model.self="showLeadPanel" class="w-full sm:max-w-lg lg:max-w-6xl 2xl:max-w-7xl">
        @if ($this->selectedLead)
            <livewire:tenant.leads.show :lead="$this->selectedLead" :panel="true" :key="'lead-panel-'.$this->selectedLead->id" />
        @endif
    </flux:modal>
</div>
