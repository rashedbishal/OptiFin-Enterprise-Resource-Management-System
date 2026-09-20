@php
    // Shared by the Leads table (leads.blade.php) and the Kanban card
    // (partials/lead-card.blade.php) so the "first 2 + N more" capping rule
    // only lives in one place. $tags is expected already eager-loaded
    // (Index::leads()/boardLeads() both load 'tags') — never queried here,
    // to avoid an N+1 across a table page or a board full of cards.
    $limit ??= 2;
    $emptyDash ??= false;
    $visibleTags = $tags->take($limit);
    $remaining = $tags->count() - $visibleTags->count();
@endphp

@if ($tags->isEmpty())
    @if ($emptyDash)
        <span class="font-medium text-zinc-400 dark:text-zinc-500">—</span>
    @endif
@else
    <div class="flex flex-wrap items-center gap-1.5">
        @foreach ($visibleTags as $tag)
            <flux:badge size="sm" variant="pill" :color="$tag->color" class="max-w-full truncate !px-2.5 py-0.5 text-xs font-semibold shadow-2xs">
                {{ $tag->name }}
            </flux:badge>
        @endforeach
        @if ($remaining > 0)
            <flux:badge size="sm" variant="pill" color="zinc" class="shrink-0 !px-2.5 py-0.5 text-xs font-semibold shadow-2xs">
                +{{ $remaining }}
            </flux:badge>
        @endif
    </div>
@endif
