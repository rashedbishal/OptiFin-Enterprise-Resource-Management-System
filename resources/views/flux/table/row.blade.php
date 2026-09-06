@blaze(fold: true, safe: ['key'])

@props([
    'key' => null,
    'sticky' => false,
])

@php
$classes = Flux::classes()
    ->add($sticky ? 'last:sticky last:bottom-0 last:z-20' : '')
    // Hover-only background tint, no borders/shadows — layered under
    // any row/cell content, so a row rendering its own dynamic status/
    // category background stays visually dominant rather than being
    // flattened by this generic affordance.
    ->add('transition-colors duration-150 hover:bg-zinc-50 dark:hover:bg-white/[3%]')
    ;
@endphp

<tr @if ($key) wire:key="table-{{ $key }}" @endif {{ $attributes->class($classes) }} data-flux-row>
    {{ $slot }}
</tr>
