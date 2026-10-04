<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

{{-- PWA install support — primarily so WhatsApp inbound-call Web Push
notifications can work on iOS at all: Safari only grants push to a site
actually launched from an installed home-screen icon (apple-mobile-web-app-
capable=yes), never a normal bookmarked/pinned tab, no matter what else is
built. See resources/js/softphone/push.js and public/sw.js. --}}
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#0e1826">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'Laravel') }}">

@fonts

{{-- Theme style (classic / neo / glass / titanium / spatial / lumen /
fjord) must be on <html> before the
stylesheet paints, or every load flashes the classic theme first. Mirrors
readThemeStyle() in resources/js/theme-style.js, which takes over after load. --}}
<script>
    (function () {
        var style = 'classic';
        try { style = window.localStorage.getItem('optifin.theme-style') || style; } catch (e) {}
        document.documentElement.dataset.theme = ['classic', 'neo', 'glass', 'titanium', 'spatial', 'lumen', 'fjord'].indexOf(style) > -1 ? style : 'classic';
    })();
</script>

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
