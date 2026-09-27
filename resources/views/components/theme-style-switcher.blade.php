{{-- Theme style switcher (Classic / Neumorphic / Glass) — sits under the
light/dark appearance control in every user menu. The two are independent:
appearance toggles the .dark class, this sets data-theme on <html>, so each
style has its own light and dark variant. State lives in the `themeStyle`
Alpine store (resources/js/theme-style.js); styles live in
resources/css/themes/*.css. --}}
<div {{ $attributes->class('px-1 py-1.5') }} x-data>
    <flux:radio.group variant="segmented" size="sm" x-model="$store.themeStyle.current" class="w-full" :aria-label="__('Theme style')" data-theme-style-switcher>
        <flux:radio value="classic" :label="__('Classic')" />
        <flux:radio value="neo" :label="__('Neo')" />
        <flux:radio value="glass" :label="__('Glass')" />
    </flux:radio.group>
</div>
