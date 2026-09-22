@blaze(fold: true)

@props([
    'sticky' => false,
])

@php
    $classes = Flux::classes()
        ->add($sticky ? 'sticky top-0 z-20' : '')
        // Warm Ledger header — a light gold-tinted band against the card's
        // now near-white fill (.card-surface, see app.css). Toned down from
        // an earlier, more saturated gold once the surrounding card fill
        // went back to white — the same gold saturation read as too heavy
        // against a brighter card body. The gold underline lives per-cell,
        // see flux/table/column.blade.php.
        ->add('bg-[#f3ead6] dark:bg-transparent')
    ;
@endphp

<thead {{ $attributes->class($classes) }} data-flux-columns>
    <tr {{ isset($tr) ? $tr->attributes : '' }}>
        {{ $tr ?? $slot }}
    </tr>
</thead>
