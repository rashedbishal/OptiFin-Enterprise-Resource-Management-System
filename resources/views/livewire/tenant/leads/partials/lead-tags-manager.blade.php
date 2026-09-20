@php
    // Rendered as part of App\Livewire\Tenant\Leads\Show's own view tree
    // (included from both lead-hero.blade.php and header.blade.php), so
    // $this is the Show component instance — same "call $this->x directly
    // in a shared partial" pattern score-breakdown.blade.php already uses,
    // no separate prop-passing needed for canUpdate/isClientAdmin.
    $canUpdate = $this->canUpdate;
@endphp

<div class="flex flex-wrap items-center gap-2">
    @foreach ($lead->tags as $tag)
        <div wire:key="lead-tag-chip-{{ $tag->id }}" class="inline-flex items-center">
            <flux:badge size="sm" variant="pill" :color="$tag->color" class="inline-flex items-center gap-1.5 max-w-56 truncate text-xs font-semibold px-3 py-1 shadow-2xs">
                <span class="truncate">{{ $tag->name }}</span>
                @if ($canUpdate)
                    <button
                        type="button"
                        class="inline-flex items-center justify-center size-3.5 rounded-full hover:bg-black/20 dark:hover:bg-white/20 transition-colors cursor-pointer"
                        aria-label="{{ __('Remove tag :tag', ['tag' => $tag->name]) }}"
                        wire:click="detachTag('{{ $tag->id }}')"
                        wire:loading.attr="disabled"
                        wire:target="detachTag('{{ $tag->id }}')"
                    >
                        <flux:icon.x-mark class="size-3" />
                    </button>
                @endif
            </flux:badge>
        </div>
    @endforeach

    @if ($canUpdate)
        @if ($this->availableTagsToAttach->isNotEmpty())
            {{-- No inline tag creation here by design — the catalog is
            managed exclusively at /leads/tags (see LeadTags\Index); this
            picker only ever offers tags that already exist in the
            catalog. --}}
            <form wire:submit="attachTag" class="flex items-center gap-1.5">
                @php
                    $availableTagOptions = collect($this->availableTagsToAttach)
                        ->map(fn ($t) => ['value' => (string) $t->id, 'label' => $t->name])
                        ->toArray();
                @endphp
                <x-dropdown-select wire:model="selectedTagId" :placeholder="__('Add tag…')" :options="$availableTagOptions" class="min-w-36" />
                <flux:button type="submit" size="xs" variant="primary" icon="plus" aria-label="{{ __('Add tag') }}" class="font-semibold shadow-2xs" />
            </form>
        @endif

        @if ($this->isClientAdmin)
            <flux:button size="xs" icon="tag" :href="route('tenant-leads.tags')" wire:navigate class="font-semibold text-xs rounded-full border border-zinc-300 dark:border-zinc-700 bg-white hover:bg-zinc-100 dark:bg-zinc-800 dark:hover:bg-zinc-700 text-zinc-900 dark:text-zinc-100 shadow-2xs">
                {{ __('Manage Tags') }}
            </flux:button>
        @endif
    @endif
</div>
