@blaze(fold: true)

@php
$classes = Flux::classes()
    ->add('[:where(&)]:min-w-48 p-[.3125rem]')
    ->add('rounded-lg shadow-xs')
    ->add('border border-zinc-200 dark:border-zinc-600')
    // Selective glass: menus are one of the few floating/transient
    // surfaces this app applies translucency+blur to (alongside the
    // mobile header) — high opacity, small blur radius, so text inside
    // stays crisp and this never reads as a large blurred region.
    ->add('bg-white/95 dark:bg-zinc-700/95 backdrop-blur-sm')
    ->add('focus:outline-hidden')
    ;
@endphp

<ui-menu
    {{ $attributes->class($classes) }}
    popover="manual"
    data-flux-menu
>
    {{ $slot }}
</ui-menu>
