@php
    // Rendered as part of App\Livewire\Tenant\Leads\Show's own view tree
    // (included from both show.blade.php and show-panel.blade.php), so
    // $this is the Show component instance — same "call $this->x directly
    // in a shared partial" pattern deals-card.blade.php/lead-tags-manager.
    // blade.php already use.
    $canUpdate = $this->canUpdate;
@endphp

<flux:card class="flex flex-col gap-4">
    <div class="flex items-center justify-between border-b border-zinc-100 pb-3 dark:border-zinc-800">
        <div class="flex items-center gap-2">
            <div class="flex size-7 items-center justify-center rounded-lg bg-teal-50 text-teal-600 dark:bg-teal-950/60 dark:text-teal-400">
                <flux:icon.document-text variant="micro" class="size-4" />
            </div>
            <flux:heading size="lg" class="font-semibold">{{ __('Custom Fields') }}</flux:heading>
        </div>
        @if ($canUpdate && ! $editingCustomFields && $this->activeCustomFieldDefinitions->isNotEmpty())
            <flux:button size="sm" icon="pencil-square" wire:click="startEditingCustomFields" class="cursor-pointer">{{ __('Edit') }}</flux:button>
        @endif
    </div>

    @if ($editingCustomFields)
        <form wire:submit="updateCustomFields" class="flex flex-col gap-4 rounded-xl border border-zinc-200/90 bg-zinc-50/50 p-4 dark:border-zinc-800 dark:bg-zinc-900/50">
            <div class="grid gap-4 sm:grid-cols-2">
                @include('livewire.tenant.leads.partials.custom-fields-form-fields', ['definitions' => $this->activeCustomFieldDefinitions])
            </div>
            <div class="flex justify-end gap-2 border-t border-zinc-200/80 pt-3 dark:border-zinc-800">
                <flux:button type="button" size="sm" wire:click="cancelEditingCustomFields" class="cursor-pointer">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" size="sm" variant="primary" class="cursor-pointer">{{ __('Save') }}</flux:button>
            </div>
        </form>
    @else
        <div class="flex flex-col gap-2.5">
            @forelse ($this->customFieldDisplayRows as $row)
                @php
                    $def = $row['definition'];
                    $type = $def->type->value ?? 'text';
                    $val = $row['value'];
                    $hasVal = $val !== null && $val !== '';
                @endphp
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-white/70 bg-white/40 bg-[image:linear-gradient(165deg,rgb(255_255_255_/_0.22),rgb(255_248_224_/_0.12))] backdrop-blur-[20px] backdrop-saturate-[260%] shadow-[inset_0_1px_1px_rgb(255_255_255_/_0.75),inset_0_-1px_1px_rgb(120_90_40_/_0.05)] p-3 dark:border-zinc-800 dark:bg-zinc-900 dark:bg-[image:none] dark:backdrop-blur-none dark:backdrop-saturate-100 dark:shadow-2xs" wire:key="custom-field-display-{{ $def->id }}">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                            @switch($type)
                                @case('number')
                                    <flux:icon.hashtag variant="micro" class="size-4" />
                                    @break
                                @case('date')
                                    <flux:icon.calendar variant="micro" class="size-4" />
                                    @break
                                @case('select')
                                    <flux:icon.queue-list variant="micro" class="size-4" />
                                    @break
                                @default
                                    <flux:icon.document-text variant="micro" class="size-4" />
                            @endswitch
                        </div>
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="text-xs sm:text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ $def->name }}</span>
                            @if (! $def->is_active)
                                <flux:badge size="sm" color="zinc" variant="pill">{{ __('Archived') }}</flux:badge>
                            @endif
                        </div>
                    </div>

                    <div class="text-end ms-auto">
                        @if ($hasVal)
                            @if ($type === 'select')
                                <span class="inline-flex items-center rounded-lg bg-teal-50 px-2.5 py-1 text-xs font-semibold text-teal-800 dark:bg-teal-950/60 dark:text-teal-300 border border-teal-200/80 dark:border-teal-800/60">
                                    {{ $val }}
                                </span>
                            @elseif ($type === 'date')
                                @php
                                    $formattedDate = $val;
                                    try {
                                        $formattedDate = \Illuminate\Support\Carbon::parse($val)->format('M j, Y');
                                    } catch (\Throwable $e) {
                                    }
                                @endphp
                                <span class="text-xs sm:text-sm font-bold text-zinc-900 dark:text-white">
                                    {{ $formattedDate }}
                                </span>
                            @else
                                <span class="text-xs sm:text-sm font-semibold text-zinc-900 dark:text-white break-words">
                                    {{ $val }}
                                </span>
                            @endif
                        @else
                            <span class="text-xs font-medium text-zinc-400 dark:text-zinc-500 italic">—</span>
                        @endif
                    </div>
                </div>
            @empty
                <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-200 p-6 text-center dark:border-zinc-800">
                    <div class="mb-2 flex size-10 items-center justify-center rounded-full bg-zinc-100 text-zinc-400 dark:bg-zinc-800 dark:text-zinc-500">
                        <flux:icon.document-text class="size-5" />
                    </div>
                    <flux:text variant="subtle" class="text-xs font-medium">{{ __('No custom fields defined yet.') }}</flux:text>
                </div>
            @endforelse
        </div>
    @endif
</flux:card>

