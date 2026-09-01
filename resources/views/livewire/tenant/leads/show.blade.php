<div class="flex flex-1 flex-col gap-6">
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard')],
        ['label' => __('Leads'), 'href' => route('tenant-leads.index')],
        ['label' => $lead->name],
    ]" />

    <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
            @if ($lead->avatarUrl())
                <img src="{{ $lead->avatarUrl() }}" alt="" class="size-12 shrink-0 rounded-full object-cover">
            @endif
            <div>
                <flux:heading size="xl">{{ $lead->name }}</flux:heading>
                @if ($lead->company)
                    <flux:text variant="strong">{{ $lead->company }}</flux:text>
                @endif
                <flux:text>{{ $lead->email }} {{ $lead->phone ? '· '.$lead->phone : '' }}</flux:text>
            </div>
        </div>
        @if ($this->isClientAdmin)
            <flux:button variant="danger" size="sm" wire:click="delete" wire:confirm="{{ __('Delete this lead?') }}">
                {{ __('Delete') }}
            </flux:button>
        @endif
    </div>

    <div class="grid gap-6 md:grid-cols-3">
        <div class="flex flex-col gap-6 md:col-span-2">
            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Details') }}</flux:heading>

                <div class="mb-4 flex items-center gap-3">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-700">
                        <flux:icon :icon="$lead->source->type->icon()" class="size-5 text-zinc-500 dark:text-zinc-300" />
                    </div>
                    <div>
                        <flux:text variant="strong">{{ $lead->source->name }}</flux:text>
                        <flux:text size="sm" variant="subtle">
                            {{ $lead->source->type->label() }}
                            @if ($lead->created_at)
                                &middot; {{ $lead->created_at->diffForHumans() }}
                            @endif
                        </flux:text>
                    </div>
                </div>

                @php
                    $hasCrmDetails = collect([
                        $lead->website, $lead->industry, $lead->location, $lead->service_interest,
                        $lead->estimated_budget, $lead->deal_value, $lead->win_probability, $lead->next_follow_up_at,
                    ])->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty();
                @endphp
                @if ($hasCrmDetails)
                    <flux:separator class="mb-4" />
                    <div class="mb-4 grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3">
                        @if ($lead->website)
                            <div>
                                <flux:text size="sm" variant="subtle">{{ __('Website') }}</flux:text>
                                <flux:text><a href="{{ $lead->website }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $lead->website }}</a></flux:text>
                            </div>
                        @endif
                        @if ($lead->industry)
                            <div>
                                <flux:text size="sm" variant="subtle">{{ __('Industry') }}</flux:text>
                                <flux:text>{{ $lead->industry }}</flux:text>
                            </div>
                        @endif
                        @if ($lead->location)
                            <div>
                                <flux:text size="sm" variant="subtle">{{ __('Location') }}</flux:text>
                                <flux:text>{{ $lead->location }}</flux:text>
                            </div>
                        @endif
                        @if ($lead->service_interest)
                            <div>
                                <flux:text size="sm" variant="subtle">{{ __('Service Interested') }}</flux:text>
                                <flux:text>{{ $lead->service_interest }}</flux:text>
                            </div>
                        @endif
                        @if ($lead->estimated_budget !== null)
                            <div>
                                <flux:text size="sm" variant="subtle">{{ __('Est. Budget') }}</flux:text>
                                <flux:text>{{ $lead->tenant->currency }} {{ number_format((float) $lead->estimated_budget, 2) }}</flux:text>
                            </div>
                        @endif
                        @if ($lead->deal_value !== null)
                            <div>
                                <flux:text size="sm" variant="subtle">{{ __('Deal Value') }}</flux:text>
                                <flux:text>{{ $lead->tenant->currency }} {{ number_format((float) $lead->deal_value, 2) }}</flux:text>
                            </div>
                        @endif
                        @if ($lead->win_probability !== null)
                            <div>
                                <flux:text size="sm" variant="subtle">{{ __('Win Probability') }}</flux:text>
                                <flux:text>{{ $lead->win_probability }}%</flux:text>
                            </div>
                        @endif
                        @if ($lead->next_follow_up_at)
                            <div>
                                <flux:text size="sm" variant="subtle">{{ __('Next Follow-up') }}</flux:text>
                                <flux:text>{{ $lead->next_follow_up_at->format('M j, Y') }}</flux:text>
                            </div>
                        @endif
                    </div>
                @endif

                @if (count($lead->messageTimeline()) > 0)
                    {{-- Webhook-sourced leads (Messenger/Instagram/WhatsApp) can carry
                    several text messages and attachments over time — shown here as a
                    single feed, in the order they actually arrived, rather than as a
                    flattened message blob with attachments clustered separately. Gated
                    on messageTimeline() specifically (not just "has any attachment"):
                    an email lead can have attachments too, but it isn't a threaded
                    conversation and should keep its own body/HTML rendering below. --}}
                    @unless ($hasCrmDetails)
                        <flux:separator class="mb-4" />
                    @endunless

                    <div class="flex max-h-96 flex-col gap-3 overflow-y-auto pe-1">
                        @foreach ($this->timeline as $item)
                            @if ($item['type'] === 'text')
                                <flux:text class="whitespace-pre-line">{{ $item['text'] }}</flux:text>
                            @else
                                @include('livewire.tenant.leads.partials.attachment-item', ['lead' => $lead, 'attachment' => $item['attachment']])
                            @endif
                        @endforeach
                    </div>
                @elseif ($lead->message || $lead->html_body || $lead->attachments->isNotEmpty())
                    @unless ($hasCrmDetails)
                        <flux:separator class="mb-4" />
                    @endunless

                    @if ($lead->html_body)
                        <div class="mb-3 flex gap-2">
                            <flux:button size="xs" :variant="$bodyView === 'text' ? 'primary' : 'ghost'" wire:click="$set('bodyView', 'text')">
                                {{ __('Plain Text') }}
                            </flux:button>
                            <flux:button size="xs" :variant="$bodyView === 'html' ? 'primary' : 'ghost'" wire:click="$set('bodyView', 'html')">
                                {{ __('Original Email') }}
                            </flux:button>
                        </div>
                    @endif

                    @if ($bodyView === 'html' && $lead->html_body)
                        {{-- sandbox with no `allow-*` tokens: no script execution, no
                        same-origin/storage access, no navigation — the safest way to
                        render HTML from an untrusted external sender. --}}
                        <iframe
                            srcdoc="{{ $lead->html_body }}"
                            sandbox
                            class="h-[500px] w-full rounded border border-neutral-200 bg-white dark:border-neutral-700"
                        ></iframe>
                    @elseif ($lead->message)
                        <flux:text class="whitespace-pre-line">{{ $lead->message }}</flux:text>
                    @endif

                    @if ($lead->attachments->isNotEmpty())
                        <div class="mt-3 flex flex-col gap-2">
                            @foreach ($lead->attachments as $attachment)
                                @include('livewire.tenant.leads.partials.attachment-item', ['lead' => $lead, 'attachment' => $attachment])
                            @endforeach
                        </div>
                    @endif
                @endif
            </flux:card>

            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Activity') }}</flux:heading>
                <div class="flex max-h-96 flex-col gap-4 overflow-y-auto pe-1">
                    @forelse ($this->activity as $event)
                        <div class="border-b border-neutral-200 pb-3 last:border-0 dark:border-neutral-700">
                            <flux:text class="text-xs text-zinc-500">{{ $event['created_at']?->diffForHumans() }} · {{ $event['user'] ?? __('System') }}</flux:text>
                            @if ($event['type'] === 'status_change')
                                <flux:text>{{ __('Status changed to :status', ['status' => ucfirst(str_replace('_', ' ', $event['new_status']))]) }}</flux:text>
                            @elseif ($event['type'] === 'assignment')
                                <flux:text>{{ __('Assigned to :name', ['name' => $event['assigned_to']]) }}</flux:text>
                            @else
                                <flux:text>{{ $event['note'] }}</flux:text>
                            @endif
                            @if (! empty($event['note']) && $event['type'] !== 'note')
                                <flux:text class="text-xs italic">{{ $event['note'] }}</flux:text>
                            @endif
                        </div>
                    @empty
                        <flux:text>{{ __('No activity yet.') }}</flux:text>
                    @endforelse
                </div>
            </flux:card>

            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Add Note') }}</flux:heading>
                <form wire:submit="addNote" class="flex flex-col gap-3">
                    <flux:textarea wire:model="newNote" :label="__('Note')" />
                    <div>
                        <flux:button type="submit" variant="primary" size="sm">{{ __('Add Note') }}</flux:button>
                    </div>
                </form>
            </flux:card>
        </div>

        <div class="flex flex-col gap-6">
            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Status') }}</flux:heading>
                <form wire:submit="updateStatus" class="flex flex-col gap-3">
                    <flux:select wire:model="status" :label="__('Status')">
                        @foreach ($this->statusOptions() as $option)
                            <flux:select.option value="{{ $option }}">{{ ucfirst(str_replace('_', ' ', $option)) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="priority" :label="__('Priority')">
                        @foreach ($this->priorityOptions() as $option)
                            <flux:select.option value="{{ $option }}">{{ ucfirst($option) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button type="submit" variant="primary" size="sm">{{ __('Update') }}</flux:button>
                </form>
            </flux:card>

            @if ($this->isClientAdmin)
                <flux:card>
                    <flux:heading size="lg" class="mb-4">{{ __('Assignment') }}</flux:heading>
                    <form wire:submit="assign" class="flex flex-col gap-3">
                        <flux:select wire:model="assigneeId" :label="__('Assigned To')" placeholder="{{ __('Unassigned') }}">
                            @foreach ($this->assignableUsers as $assignableUser)
                                <flux:select.option value="{{ $assignableUser->id }}">{{ $assignableUser->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:button type="submit" variant="primary" size="sm">{{ __('Assign') }}</flux:button>
                    </form>
                </flux:card>
            @endif
        </div>
    </div>
</div>
