@blaze(fold: true)

@props([
    'sticky' => false,
])

@php
    $classes = Flux::classes()
        ->add($sticky ? 'sticky top-0 z-20' : '')
        // Warm Ledger header — a deeper-tinted glass band riding on top of
        // the table's own .card-surface glass (flux/table/index.blade.php),
        // so it reads as "part of the same frosted card" rather than a
        // mismatched opaque accent. The gold underline lives per-cell, see
        // flux/table/column.blade.php.
        ->add('bg-white/45 bg-[linear-gradient(165deg,rgb(255_255_255_/_0.3),rgb(251_224_120_/_0.16))] backdrop-blur-md backdrop-saturate-[220%] dark:bg-transparent dark:bg-none dark:backdrop-blur-none dark:backdrop-saturate-100')
    ;
@endphp

<thead {{ $attributes->class($classes) }} data-flux-columns>
    <tr {{ isset($tr) ? $tr->attributes : '' }}>
        {{ $tr ?? $slot }}
    </tr>
</thead>
