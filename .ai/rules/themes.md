---
paths:
  - 'resources/css/themes/**'
---

# Themes

## Theme styles (classic/neo/glass) live in data-theme, orthogonal to .dark
Three switchable styles: Classic (all of app.css), Neumorphic (themes/neo.css), Glass (themes/glass.css), selected by `data-theme` on <html> and independent of Flux's `.dark` class, so each has light+dark. Switcher = `<x-theme-style-switcher />` (under the appearance control in all 4 user menus) → Alpine store `themeStyle` in resources/js/theme-style.js, persisted in localStorage `optifin.theme-style`; an inline script in partials/head.blade.php sets it pre-paint.
Traps: (1) wire:navigate REMOVES <html> attributes the server HTML lacks, so data-theme is re-applied in `livewire:navigating` → `onSwap` (runs before paint) — never render data-theme server-side in only some layouts. (2) The classic unlayered neumorphism block in app.css is gated with `:root:not([data-theme="neo"], [data-theme="glass"])`; keep new classic unlayered box-shadow rules gated too or they leak into neo/glass. (3) Flux modals with scroll="body" make the <dialog> full-viewport (card is `[data-flux-modal-content]`) — only style `dialog.rounded-xl` as a card. (4) Theme CSS targets hooks: `data-flux-variant` on flux buttons (published stub), `.app-topbar`, `.kanban-column`, `.kanban-card`; keep them when editing those views. (5) Neo shadows are 4-layer [outer dark, outer light, inset dark, inset light] so raised↔pressed interpolate — keep that shape for new states.
