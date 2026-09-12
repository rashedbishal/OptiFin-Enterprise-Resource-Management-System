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
        <div wire:key="lead-tag-chip-{{ $tag->id }}" class="inline-flex items-center gap-0.5">
            <flux:badge size="sm" variant="pill" :color="$tag->color" class="max-w-40 truncate">{{ $tag->name }}</flux:badge>
            @if ($canUpdate)
                <flux:button
                    size="xs"
                    variant="ghost"
                    icon="x-mark"
                    class="!size-4 !p-0"
                    aria-label="{{ __('Remove tag :tag', ['tag' => $tag->name]) }}"
                    wire:click="detachTag('{{ $tag->id }}')"
                    wire:loading.attr="disabled"
                    wire:target="detachTag('{{ $tag->id }}')"
                />
            @endif
        </div>
    @endforeach

    @if ($canUpdate)
        @if ($this->availableTagsToAttach->isNotEmpty())
            {{-- No inline tag creation here by design — the catalog is
            managed exclusively at /leads/tags (see LeadTags\Index); this
            picker only ever offers tags that already exist in the
            catalog. --}}
            <form wire:submit="attachTag" class="flex items-center gap-1">
                @php
                    $availableTagOptions = collect($this->availableTagsToAttach)
                        ->map(fn ($t) => ['value' => (string) $t->id, 'label' => $t->name])
                        ->toArray();
                @endphp
                <x-dropdown-select wire:model="selectedTagId" :placeholder="__('Add tag…')" :options="$availableTagOptions" class="min-w-36" />
                <flux:button type="submit" size="xs" variant="ghost" icon="plus" aria-label="{{ __('Add tag') }}" />
            </form>
        @endif

        @if ($this->isClientAdmin)
            <flux:button size="xs" variant="ghost" :href="route('tenant-leads.tags')" wire:navigate>
                {{ __('Manage Tags') }}
            </flux:button>
        @endif
    @endif
</div>
