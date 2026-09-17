{{-- The OptiFin brand mark. Rendered as an <img> (not inline SVG) so the
multicolor artwork keeps its own colors instead of inheriting the parent's
currentColor; sizing stays controllable via the class attributes passed by
each call site. --}}
<img src="{{ asset('img/logo-mark.png') }}" alt="{{ config('app.name', 'Laravel') }} logo" {{ $attributes }} />
