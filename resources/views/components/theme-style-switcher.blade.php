{{-- Theme style switcher — sits under the light/dark appearance control in
every user menu. The two are independent: appearance toggles the .dark
class, this sets data-theme on <html>, so each style has its own light and
dark variant. State lives in the `themeStyle` Alpine store
(resources/js/theme-style.js); styles live in resources/css/themes/*.css.
Five text labels don't fit a user menu's width, so each style is a small
swatch of its material (themes/switcher.css) with the active name below. --}}
@php
    $styles = [
        'classic' => __('Classic'),
        'neo' => __('Neumorphic'),
        'glass' => __('Glass'),
        'titanium' => __('Titanium'),
        'spatial' => __('Spatial'),
    ];
@endphp

{{-- Swatches are spans, not buttons: flux:menu turns every <a>/<button>
inside it into a role="menuitem" that closes the menu on click, and the
point of this control is flipping between styles while the menu stays open.
So it's a hand-rolled radiogroup: roving tabindex, arrow keys move and
select, Enter/Space select. --}}
<div
    {{ $attributes->class('px-1 py-1.5') }}
    x-data="{
        labels: @js($styles),
        select(style) {
            $store.themeStyle.current = style;
            this.$nextTick(() => this.$root.querySelector(`[data-style='${style}']`)?.focus());
        },
        step(offset) {
            const styles = Object.keys(this.labels);
            const index = styles.indexOf($store.themeStyle.current);
            this.select(styles[(index + offset + styles.length) % styles.length]);
        },
    }"
    data-theme-style-switcher
>
    <div
        role="radiogroup"
        aria-label="{{ __('Theme style') }}"
        class="theme-swatches"
        x-on:keydown.arrow-right.prevent.stop="step(1)"
        x-on:keydown.arrow-down.prevent.stop="step(1)"
        x-on:keydown.arrow-left.prevent.stop="step(-1)"
        x-on:keydown.arrow-up.prevent.stop="step(-1)"
    >
        @foreach ($styles as $value => $label)
            <span
                role="radio"
                class="theme-swatch theme-swatch--{{ $value }}"
                data-style="{{ $value }}"
                title="{{ $label }}"
                aria-label="{{ $label }}"
                tabindex="-1"
                x-bind:tabindex="$store.themeStyle.current === '{{ $value }}' ? 0 : -1"
                x-bind:aria-checked="$store.themeStyle.current === '{{ $value }}' ? 'true' : 'false'"
                x-on:click.stop="select('{{ $value }}')"
                x-on:keydown.enter.prevent.stop="select('{{ $value }}')"
                x-on:keydown.space.prevent.stop="select('{{ $value }}')"
            ></span>
        @endforeach
    </div>
    <p class="mt-1.5 text-center text-xs text-zinc-500 dark:text-zinc-400">
        {{ __('Style') }}: <span class="font-medium text-zinc-700 dark:text-zinc-200" x-text="labels[$store.themeStyle.current]">{{ $styles['classic'] }}</span>
    </p>
</div>
