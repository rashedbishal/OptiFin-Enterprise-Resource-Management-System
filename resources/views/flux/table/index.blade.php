@blaze(fold: true)

@props([
    'paginate' => null,
])

@php
$classes = Flux::classes()
    ->add('[:where(&)]:min-w-full table-fixed border-separate border-spacing-0 isolate')
    ->add('text-zinc-800')
    // We want whitespace-nowrap for the table, but not for modals and dropdowns...
    ->add('whitespace-nowrap [&_dialog]:whitespace-normal [&_[popover]]:whitespace-normal')
    ;

// card-surface (see app.css): most flux:table usages in this app sit
// directly on the canvas rather than inside a flux:card — without this
// they were indistinguishable from the bare background. Same opaque
// surface + top-edge highlight + shadow flux:card uses, just with
// overflow-hidden (not padding) since the table's own cells already
// carry their own spacing.
$containerClasses = Flux::classes()
    ->add('flex flex-col overflow-hidden rounded-xl border card-surface inset-ring-1 inset-ring-white/60 dark:inset-ring-white/10')
    ->add($attributes->pluck('container:class'))
    ;
@endphp

<div class="{{ $containerClasses }}">
    {{ $header ?? '' }}

    <ui-table-scroll-area class="overflow-auto">
        <table {{ $attributes->class($classes) }} data-flux-table>
            {{ $slot }}
        </table>
    </ui-table-scroll-area>

    {{ $footer ?? '' }}

    <?php if ($paginate): ?>
        <?php $paginationAttributes = Flux::attributesAfter('pagination:', $attributes, ['paginator' => $paginate, 'class' => 'shrink-0']); ?>
        <flux:pagination :attributes="$paginationAttributes" />
    <?php endif; ?>
</div>
