<div class="flex flex-1 flex-col gap-3.5 lg:gap-6">
    <div class="sticky top-0 z-10 -mx-4 flex flex-col gap-2 bg-white px-4 py-3 lg:-mx-8 lg:gap-3.5 lg:px-8 lg:py-4 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
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
            $statusOptionsFormatted = array_merge(
                [['value' => '', 'label' => __('All statuses')]],
                collect($this->statusOptions())->map(fn($opt) => ['value' => (string) $opt->id, 'label' => $opt->label()])->toArray()
            );
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

        <div class="grid grid-cols-2 gap-2 md:grid-cols-4 lg:gap-3">
            <x-dropdown-select wire:model.live="status" :placeholder="__('All statuses')" :options="$statusOptionsFormatted" icon="squares-2x2" />
            <x-dropdown-select wire:model.live="priority" :placeholder="__('All priorities')" :options="$priorityOptionsFormatted" icon="funnel" />
            <x-dropdown-select wire:model.live="sourceType" :placeholder="__('All sources')" :options="$sourceTypeOptionsFormatted" icon="tag" />

            @if ($this->isClientAdmin)
                <x-dropdown-select wire:model.live="assignedTo" :placeholder="__('All users')" :options="$assignedToOptionsFormatted" icon="user" />
            @endif
        </div>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Source') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Priority') }}</flux:table.column>
            <flux:table.column>{{ __('Assigned To') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->facebookLeadAdsSources as $source)
                <flux:table.row wire:key="fb-lead-ads-source-{{ $source->id }}">
                    <flux:table.cell>
                        <div class="flex flex-col">
                            <span>{{ $source->name }}</span>
                            <flux:text size="sm" variant="subtle">
                                {{ $source->leads_max_created_at ? __('Last submission :time', ['time' => \Illuminate\Support\Carbon::parse($source->leads_max_created_at)->diffForHumans()]) : __('No submissions yet') }}
                            </flux:text>
                        </div>
                    </flux:table.cell>
                    <flux:table.cell><flux:badge size="sm" icon="{{ $source->type->icon() }}">{{ $source->type->label() }}</flux:badge></flux:table.cell>
                    <flux:table.cell><flux:badge size="sm" color="zinc">{{ trans_choice(':count lead|:count leads', $source->leads_count, ['count' => $source->leads_count]) }}</flux:badge></flux:table.cell>
                    <flux:table.cell>—</flux:table.cell>
                    <flux:table.cell>—</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex justify-end gap-2">
                            @if ($source->leads_count > 0)
                                <flux:button size="sm" :href="route('tenant-leads.source', $source)" wire:navigate>{{ __('View All') }}</flux:button>
                            @elseif ($this->canDeleteLeads)
                                <flux:button size="sm" variant="danger" wire:click="deleteSource('{{ $source->id }}')" wire:confirm="{{ __('Delete this lead source?') }}">
                                    {{ __('Delete') }}
                                </flux:button>
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
            @forelse ($this->leads as $lead)
                <flux:table.row wire:key="lead-{{ $lead->id }}">
                    <flux:table.cell>
                        <div class="flex items-center gap-2">
                            @if ($lead->avatarUrl())
                                <img src="{{ $lead->avatarUrl() }}" alt="" loading="lazy" class="size-6 shrink-0 rounded-full object-cover">
                            @endif
                            {{ $lead->name }}
                        </div>
                    </flux:table.cell>
                    <flux:table.cell><flux:badge size="sm" icon="{{ $lead->source->type->icon() }}">{{ $lead->source->type->label() }}</flux:badge></flux:table.cell>
                    <flux:table.cell><flux:badge size="sm" :color="$lead->status->color()">{{ $lead->status->label() }}</flux:badge></flux:table.cell>
                    <flux:table.cell>{{ ucfirst($lead->priority->value) }}</flux:table.cell>
                    <flux:table.cell>{{ $lead->assignedUser?->name ?? __('Unassigned') }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex justify-end gap-2">
                            <flux:button size="sm" :href="route('tenant-leads.show', $lead)" wire:navigate>{{ __('View') }}</flux:button>
                            @if ($this->canDeleteLeads && in_array($lead->source->type->value, ['email', 'website_form', 'manual']))
                                <flux:button size="sm" variant="danger" wire:click="delete('{{ $lead->id }}')" wire:confirm="{{ __('Delete this lead?') }}">
                                    {{ __('Delete') }}
                                </flux:button>
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                @if ($this->facebookLeadAdsSources->isEmpty())
                    <flux:table.row>
                        <flux:table.cell colspan="6">{{ __('No leads yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endif
            @endforelse
        </flux:table.rows>
    </flux:table>

    {{ $this->leads->links() }}

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
</div>
