@blaze(fold: true)

@props([
    'variant' => 'outline',
    'size' => null,
])

@php
$classes = Flux::classes()
    ->add('border')
    ->add(match ($variant) {
        'soft' => '[:where(&)]:bg-black/[0.02] [:where(&)]:border-black/[0.025] dark:[:where(&)]:bg-white/[0.06] dark:[:where(&)]:border-white/[0.065]',
        // card-surface (see app.css): translucent glass background/border/
        // shadow; the inset-ring is the same bright top-edge highlight
        // technique already used on the Kanban lead-card.
        default => 'card-surface inset-ring-1 inset-ring-white/60 dark:inset-ring-white/10',
    })
    ->add(match ($size) {
        default => '[:where(&)]:p-6 [:where(&)]:rounded-xl',
        'sm' => '[:where(&)]:p-4 [:where(&)]:rounded-lg',
    })
    ;
@endphp

<div {{ $attributes->class($classes) }} data-flux-card>
    {{ $slot }}
</div>
