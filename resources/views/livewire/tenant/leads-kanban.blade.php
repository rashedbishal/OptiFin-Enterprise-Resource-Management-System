@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard')],
        ['label' => __('Leads')],
    ]" />
@endpush

<div class="flex flex-1 flex-col h-full min-h-0 overflow-hidden">
    {{-- Header: heading, actions, and filters stay fixed at top. --}}
    <div class="shrink-0 z-10 flex flex-col gap-2 bg-white px-4 py-3 lg:gap-3.5 lg:px-8 lg:py-4 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
        <div class="flex items-center justify-between gap-2">
            <flux:heading size="lg" class="hidden lg:block lg:text-2xl font-semibold">{{ __('Leads') }}</flux:heading>
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
                collect($this->sourceTypeOptions())->map(fn($opt) => ['value' => $opt, 'label' => \App\Enums\LeadSourceType::from($opt)->label()])->toArray()
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

    {{-- Main scrollable area (scrollbar touches right edge & bottom edge) --}}
    <div class="flex-1 min-h-0 overflow-auto" wire:loading.class.delay="opacity-70" wire:target="moveLead">
        <div class="p-4 sm:p-6 lg:p-8 flex flex-col gap-6 min-h-full">
        @if ($this->boardLeads->isEmpty())
            <div class="flex flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-zinc-200 py-16 text-center dark:border-zinc-700">
                <flux:icon.inbox class="size-8 text-zinc-300 dark:text-zinc-600" />
                <flux:text variant="strong">{{ __('No leads yet') }}</flux:text>
                <flux:text size="sm" variant="subtle">{{ __('New leads from your connected channels will show up here.') }}</flux:text>
            </div>
        @else
            <div>
                {{-- Desktop / tablet: Kanban board, one column per pipeline status --}}
                <div
                    class="hidden lg:flex lg:items-start lg:gap-4 min-w-max align-top"
                    x-data="{
                        scrollEl: null,
                        edge: 220,
                        topEdge: 140,
                        maxSpeed: 10,
                        lastClientX: null,
                        lastClientY: null,
                        rafId: null,
                        isDragging: false,
                        init() {
                            this.scrollEl = this.$el.closest('.overflow-auto') || this.$el;
                        },
                        handleDrag(event) {
                            if (event && typeof event.clientX === 'number' && event.clientX !== 0) {
                                this.lastClientX = event.clientX;
                            }
                            if (event && typeof event.clientY === 'number' && event.clientY !== 0) {
                                this.lastClientY = event.clientY;
                            }
                            if (! this.isDragging) {
                                this.isDragging = true;
                            }
                            if (! this.rafId) {
                                this.rafId = requestAnimationFrame(() => this.doScroll());
                            }
                        },
                        stopDrag() {
                            this.isDragging = false;
                            this.lastClientX = null;
                            this.lastClientY = null;
                            if (this.rafId) {
                                cancelAnimationFrame(this.rafId);
                                this.rafId = null;
                            }
                        },
                        doScroll() {
                            if (! this.isDragging || ! this.scrollEl) {
                                this.rafId = null;
                                return;
                            }
                            const rect = this.scrollEl.getBoundingClientRect();

                            // Horizontal edge auto-scroll (left / right)
                            if (this.lastClientX !== null) {
                                const clientX = this.lastClientX;
                                const leftThreshold = rect.left + this.edge;
                                const rightThreshold = rect.right - this.edge;

                                if (clientX < leftThreshold) {
                                    const dist = leftThreshold - clientX;
                                    const speedRatio = Math.min(1, Math.max(0, dist / this.edge)) ** 2;
                                    this.scrollEl.scrollLeft -= this.maxSpeed * speedRatio;
                                } else if (clientX > rightThreshold) {
                                    const dist = clientX - rightThreshold;
                                    const speedRatio = Math.min(1, Math.max(0, dist / this.edge)) ** 2;
                                    this.scrollEl.scrollLeft += this.maxSpeed * speedRatio;
                                }
                            }

                            // Vertical edge auto-scroll (up / down)
                            if (this.lastClientY !== null) {
                                const clientY = this.lastClientY;
                                const topThreshold = rect.top + this.topEdge;
                                const bottomThreshold = rect.bottom - this.topEdge;

                                if (clientY < topThreshold) {
                                    const dist = topThreshold - clientY;
                                    const speedRatio = Math.min(1, Math.max(0, dist / this.topEdge)) ** 2;
                                    this.scrollEl.scrollTop -= (this.maxSpeed * 1.5) * speedRatio;
                                } else if (clientY > bottomThreshold) {
                                    const dist = clientY - bottomThreshold;
                                    const speedRatio = Math.min(1, Math.max(0, dist / this.topEdge)) ** 2;
                                    this.scrollEl.scrollTop += (this.maxSpeed * 1.5) * speedRatio;
                                }
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
                            :class="dragDepth > 0 ? '{{ $statusCase->columnDropTargetClasses() }}' : '{{ $statusCase->columnBackgroundClasses() }}'"
                            class="flex w-72 min-h-32 shrink-0 flex-col rounded-lg transition-colors"
                        >
                            <div class="flex items-center justify-between gap-2 px-3 py-2.5">
                                <flux:text variant="strong" size="sm">{{ $statusCase->label() }}</flux:text>
                                <flux:badge size="sm" :color="$statusCase->color()">{{ $totalCount }}</flux:badge>
                            </div>

                            <div class="flex flex-1 flex-col gap-2 px-3 pb-3">
                                @forelse ($columnLeads as $lead)
                                    @include('livewire.tenant.leads.partials.lead-card', ['lead' => $lead, 'draggable' => true, 'canMove' => $this->canUpdateLeads, 'tenantStatuses' => $this->tenantLeadStatuses, 'tenant' => auth()->user()->tenant])
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

                {{-- Mobile: status-grouped vertical list --}}
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
                                        @include('livewire.tenant.leads.partials.lead-card', ['lead' => $lead, 'draggable' => false, 'canMove' => $this->canUpdateLeads, 'tenantStatuses' => $this->tenantLeadStatuses, 'tenant' => auth()->user()->tenant])
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
            </div>
        @endif
        </div>
    </div>

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
                                <flux:input wire:model="leadSource" :label="__('Lead Source')" placeholder="{{ __('e.g. Referral, Facebook Ad, Trade Show') }}" class="sm:col-span-2" />

                                <flux:input.group :label="__('Est. Budget')">
                                    <flux:input.group.prefix class="!px-2">
                                        <select wire:model.live="leadEstimatedBudgetCurrencyId" class="cursor-pointer border-0 bg-transparent p-0 pe-5 text-sm font-medium focus:ring-0">
                                            @foreach ($this->tenantCurrencies as $currency)
                                                <option value="{{ $currency->id }}">{{ $currency->code }}</option>
                                            @endforeach
                                        </select>
                                    </flux:input.group.prefix>
                                    <flux:input wire:model="leadEstimatedBudget" type="number" step="0.01" min="0" placeholder="0.00" />
                                </flux:input.group>

                                <flux:input.group :label="__('Deal Value')">
                                    <flux:input.group.prefix class="!px-2">
                                        <select wire:model.live="leadDealValueCurrencyId" class="cursor-pointer border-0 bg-transparent p-0 pe-5 text-sm font-medium focus:ring-0">
                                            @foreach ($this->tenantCurrencies as $currency)
                                                <option value="{{ $currency->id }}">{{ $currency->code }}</option>
                                            @endforeach
                                        </select>
                                    </flux:input.group.prefix>
                                    <flux:input wire:model="leadDealValue" type="number" step="0.01" min="0" placeholder="0.00" />
                                </flux:input.group>

                                <flux:input.group :label="__('Win Probability')">
                                    <flux:input wire:model="leadWinProbability" type="number" min="0" max="100" placeholder="50" />
                                    <flux:input.group.suffix>%</flux:input.group.suffix>
                                </flux:input.group>

                                <x-date-picker wire:model="leadNextFollowUpAt" :label="__('Next Follow-up Date')" :placeholder="__('Next Follow-up Date')" :min="now()->toDateString()" />
                                <x-datetime-picker wire:model="leadMeetingDate" :label="__('Meeting Date')" :placeholder="__('Meeting Date')" :min="now()->toDateString()" />
                            </div>
                        </div>

                        <flux:separator />

                        <div class="flex flex-col gap-4">
                            <div class="flex items-center gap-2">
                                <flux:icon.flag variant="micro" class="text-zinc-400 dark:text-zinc-500" />
                                <flux:heading size="sm">{{ __('Pipeline') }}</flux:heading>
                            </div>
                            @php
                                $createStatusOpts = collect($this->statusOptions())->map(fn($o) => ['value' => (string) $o->id, 'label' => $o->label()])->toArray();
                                $createPriorityOpts = collect($this->priorityOptions())->map(fn($o) => ['value' => $o, 'label' => ucfirst($o)])->toArray();
                                if ($this->isClientAdmin) {
                                    $createAssigneeOpts = array_merge(
                                        [['value' => '', 'label' => __('Unassigned')]],
                                        collect($this->assignableUsers)->map(fn($u) => ['value' => (string) $u->id, 'label' => $u->name])->toArray()
                                    );
                                }
                            @endphp
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-dropdown-select wire:model="leadStatus" :label="__('Pipeline Stage')" :options="$createStatusOpts" />
                                <x-dropdown-select wire:model="leadPriority" :label="__('Priority')" :options="$createPriorityOpts" />
                                @if ($this->isClientAdmin)
                                    <div class="sm:col-span-2">
                                        <x-dropdown-select wire:model="leadAssigneeId" :label="__('Assigned Sales Executive')" :placeholder="__('Unassigned')" :options="$createAssigneeOpts" />
                                    </div>
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

    <flux:modal variant="flyout" wire:model.self="showLeadPanel" class="w-full sm:max-w-lg lg:max-w-6xl xl:max-w-[85rem] 2xl:max-w-[96rem]">
        @if ($this->selectedLead)
            <livewire:tenant.leads.show :lead="$this->selectedLead" :panel="true" :key="'lead-panel-'.$this->selectedLead->id" />
        @endif
    </flux:modal>
</div>
