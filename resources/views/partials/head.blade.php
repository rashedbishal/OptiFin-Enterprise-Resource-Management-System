<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

{{-- Theme style (classic / neo / glass / titanium / spatial) must be on <html> before the
stylesheet paints, or every load flashes the classic theme first. Mirrors
readThemeStyle() in resources/js/theme-style.js, which takes over after load. --}}
<script>
    (function () {
        var style = 'classic';
        try { style = window.localStorage.getItem('optifin.theme-style') || style; } catch (e) {}
        document.documentElement.dataset.theme = ['classic', 'neo', 'glass', 'titanium', 'spatial'].indexOf(style) > -1 ? style : 'classic';
    })();
</script>

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
