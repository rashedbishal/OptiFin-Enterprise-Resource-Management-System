{{--
Shared shell for every error page (errors/{401,403,404,419,429,500,503,4xx,5xx}).
Each page @includes this with: $code, $title, $message, $icon (a Heroicon
name) and optionally $retry (true swaps the secondary "Go back" action for
"Try again", for errors where simply reloading is the fix).

No Livewire/Alpine here — plain links and inline onclick only, since an
error page must render even when the rest of the app can't. The auth
lookups are wrapped in try/catch for the same reason: a 500 caused by the
database being down would otherwise throw again while resolving the
signed-in user, and Laravel would fall back to its unstyled page.

Styling reuses the app's own theme: .card-surface glass in light mode /
navy-steel in dark mode (both from app.css), the oxblood accent, and the
same light/dark appearance the user picked (@fluxAppearance in
partials.head) — deliberately not forced to one mode.
--}}
@php
    $retry ??= false;

    try {
        $signedInHome = match (true) {
            auth('platform')->check() && Route::has('admin.dashboard') => route('admin.dashboard'),
            auth('web')->check() && Route::has('dashboard') => route('dashboard'),
            default => null,
        };
    } catch (\Throwable) {
        $signedInHome = null;
    }

    $homeUrl = $signedInHome ?? url('/');
    $homeLabel = $signedInHome ? __('Back to dashboard') : __('Back to home');
    $statusText = \Symfony\Component\HttpFoundation\Response::$statusTexts[$code] ?? __('Error');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => $title])
        <style>
            @keyframes error-page-rise {
                from {
                    opacity: 0;
                    transform: translateY(10px);
                }
            }

            .error-page-rise {
                animation: error-page-rise var(--motion-duration-standard, 650ms) var(--motion-ease-premium, ease-out) backwards;
            }
        </style>
    </head>
    <body class="min-h-svh antialiased text-zinc-800 dark:text-zinc-100">
        <div class="relative isolate flex min-h-svh flex-col overflow-hidden">
            {{-- Atmosphere: a soft accent glow behind the card and a faint
            dot grid that fades out toward the edges. Purely decorative. --}}
            <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
                <div class="absolute left-1/2 top-1/2 size-[44rem] -translate-x-1/2 -translate-y-1/2 rounded-full bg-[radial-gradient(closest-side,rgb(107_29_43/0.09),transparent)] dark:bg-[radial-gradient(closest-side,rgb(56_104_160/0.22),transparent)]"></div>
                <div class="absolute inset-0 bg-[radial-gradient(rgb(107_29_43/0.12)_1px,transparent_1px)] bg-size-[22px_22px] mask-[radial-gradient(ellipse_60%_55%_at_50%_45%,black,transparent)] dark:bg-[radial-gradient(rgb(143_163_184/0.14)_1px,transparent_1px)]"></div>
            </div>

            <header class="flex items-center justify-between px-6 py-5 sm:px-10">
                <a href="{{ $homeUrl }}" class="flex items-center gap-2.5 rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-accent">
                    <x-app-logo-icon class="size-7" />
                    <span class="text-sm font-semibold tracking-tight text-zinc-900 dark:text-white">{{ config('app.name', 'Laravel') }}</span>
                </a>

                <span class="inline-flex items-center gap-2 rounded-full border border-zinc-200/80 bg-white/60 px-3 py-1 text-xs font-medium text-zinc-600 backdrop-blur-sm dark:border-white/10 dark:bg-white/5 dark:text-zinc-300">
                    <span class="size-1.5 rounded-full bg-accent-content dark:bg-sky-400"></span>
                    {{ $code }} · {{ $statusText }}
                </span>
            </header>

            <main class="flex flex-1 items-center justify-center px-4 pb-16 pt-4 sm:px-6">
                <div class="error-page-rise w-full max-w-xl rounded-3xl border card-surface inset-ring-1 inset-ring-white/60 dark:inset-ring-white/10">
                    <div class="relative flex flex-col items-center px-6 py-12 text-center sm:px-14 sm:py-14">
                        <div class="flex size-14 items-center justify-center rounded-2xl bg-linear-to-br from-[#6b1d2b] to-[#8f2c3e] text-white shadow-lg shadow-[#6b1d2b]/25 inset-ring-1 inset-ring-white/20 dark:from-zinc-600 dark:to-zinc-700 dark:shadow-black/40 dark:inset-ring-white/10">
                            <flux:icon :icon="$icon" class="size-7" />
                        </div>

                        <p class="mt-8 bg-linear-to-b from-[#6b1d2b] to-[#6b1d2b]/35 bg-clip-text text-7xl font-semibold leading-none tracking-tighter text-transparent tabular-nums sm:text-8xl dark:from-white dark:to-white/25">
                            {{ $code }}
                        </p>

                        <h1 class="mt-6 text-2xl font-semibold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">
                            {{ $title }}
                        </h1>

                        <p class="mt-3 max-w-sm text-sm leading-relaxed text-zinc-600 sm:text-base dark:text-zinc-400">
                            {{ $message }}
                        </p>

                        <div aria-hidden="true" class="my-9 h-px w-full max-w-xs bg-linear-to-r from-transparent via-zinc-300 to-transparent dark:via-white/15"></div>

                        <div class="flex w-full flex-col-reverse items-stretch justify-center gap-3 sm:w-auto sm:flex-row sm:items-center">
                            @if ($retry)
                                <flux:button onclick="window.location.reload()" icon="arrow-path">
                                    {{ __('Try again') }}
                                </flux:button>
                            @else
                                <flux:button onclick="history.length > 1 ? history.back() : window.location.assign('{{ $homeUrl }}')" icon="arrow-left">
                                    {{ __('Go back') }}
                                </flux:button>
                            @endif

                            <flux:button variant="primary" :href="$homeUrl" icon-trailing="arrow-right">
                                {{ $homeLabel }}
                            </flux:button>
                        </div>
                    </div>
                </div>
            </main>

            <footer class="px-6 pb-8 text-center text-xs text-zinc-500 dark:text-zinc-500">
                &copy; {{ date('Y') }} {{ config('app.name', 'Laravel') }}. {{ __('All rights reserved.') }}
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
