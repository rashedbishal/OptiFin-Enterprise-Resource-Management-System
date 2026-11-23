{{--
Split "showcase" layout for the two sign-in pages: tenant /login (used as
<x-layouts::auth.showcase>) and platform /admin/login (Livewire
#[Layout('layouts.auth.showcase', ['variant' => 'platform'])]). The slot is
the form; this file owns the brand panel, the card chrome and the motion.
Styles live in the "Login showcase" section of resources/css/app.css.
--}}
@php
    $isPlatform = ($variant ?? 'tenant') === 'platform';
    $skyline = [38, 62, 46, 84, 55, 70, 100, 64, 88, 52, 76, 44, 92, 58, 34];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-svh bg-slate-100 antialiased dark:bg-zinc-950">
        <div class="auth-showcase relative isolate flex min-h-svh items-center justify-center overflow-hidden px-4 py-6 sm:px-6 sm:py-10 lg:px-10">
            {{-- Page backdrop: dotted canvas + two slow-drifting colour orbs. --}}
            <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
                <div class="auth-dots absolute inset-0"></div>
                <div class="auth-orb auth-orb--one"></div>
                <div class="auth-orb auth-orb--two"></div>
            </div>

            <div class="relative w-full max-w-md lg:grid lg:max-w-6xl lg:grid-cols-12 lg:items-center">
                {{-- Brand panel: a compact banner on phones, the big right-hand canvas on desktop. --}}
                <section
                    aria-hidden="true"
                    data-auth-parallax
                    @class([
                        'auth-panel relative flex h-52 flex-col items-center justify-center overflow-hidden rounded-[1.75rem] text-white sm:h-60',
                        'lg:col-span-8 lg:col-start-5 lg:row-start-1 lg:h-auto lg:min-h-[660px] lg:items-stretch lg:justify-between lg:rounded-[2.25rem] lg:py-12 lg:ps-[17%] lg:pe-12',
                        'auth-panel--platform' => $isPlatform,
                    ])
                >
                    <div class="auth-panel-grid absolute inset-0"></div>
                    <div class="auth-blob auth-blob--cyan"></div>
                    <div class="auth-blob auth-blob--blue"></div>

                    {{-- Rising bars along the bottom: the logo's growth chart as a skyline. --}}
                    <div class="auth-skyline absolute inset-x-0 bottom-0 flex h-[46%] items-end gap-[1.2%] px-[3%] opacity-70 lg:h-[42%]">
                        @foreach ($skyline as $height)
                            <span class="auth-skyline-bar flex-1 rounded-t-lg" style="height: {{ $height }}%; --i: {{ $loop->index }}"></span>
                        @endforeach
                    </div>

                    {{-- Mobile / tablet banner content (the card below carries the logo). --}}
                    <div class="relative z-10 -mt-10 flex flex-col items-center gap-3 px-6 text-center lg:hidden">
                        <span class="auth-chip inline-flex items-center gap-2 rounded-full px-3 py-1 text-[11px] font-medium tracking-wide">
                            <span class="auth-live-dot"></span>
                            {{ $isPlatform ? __('Platform console') : __('ERP for a smarter tomorrow') }}
                        </span>
                        <p class="auth-headline text-2xl font-semibold tracking-tight sm:text-3xl">
                            @if ($isPlatform)
                                {{ __('Every workspace,') }} <span class="auth-headline-accent">{{ __('one control room.') }}</span>
                            @else
                                {{ __('Plan. Manage.') }} <span class="auth-headline-accent">{{ __('Grow.') }}</span>
                            @endif
                        </p>
                    </div>

                    {{-- Desktop content. --}}
                    <div class="relative z-10 hidden items-center justify-between lg:flex">
                        <span class="auth-chip inline-flex items-center gap-2 rounded-full px-3.5 py-1.5 text-xs font-medium tracking-wide">
                            <span class="auth-live-dot"></span>
                            {{ $isPlatform ? __('Platform console') : __('ERP for a smarter tomorrow') }}
                        </span>
                        <img src="{{ asset('img/logo-full.png') }}" alt="" class="auth-logo-glow h-20 w-auto opacity-90" />
                    </div>

                    <div class="relative z-10 hidden max-w-lg lg:block">
                        <h2 class="auth-headline text-5xl leading-[1.05] font-semibold tracking-tight xl:text-6xl">
                            @if ($isPlatform)
                                {{ __('Every workspace,') }}<br>
                                <span class="auth-headline-accent">{{ __('one control room.') }}</span>
                            @else
                                {{ __('Plan. Manage.') }}<br>
                                <span class="auth-headline-accent">{{ __('Grow together.') }}</span>
                            @endif
                        </h2>
                        <p class="auth-subline mt-5 max-w-md text-base leading-relaxed text-blue-50/80">
                            {{ $isPlatform
                                ? __('Clients, roles, modules and system health — managed from one secure place.')
                                : __('Leads, deals, tasks and calls in one workspace, so every day moves your business forward.') }}
                        </p>

                        {{-- Decorative glass cards, desktop only. --}}
                        <div class="auth-floaters pointer-events-none mt-10 hidden items-end gap-4 xl:flex">
                            <div class="auth-glass auth-float auth-float--a w-52 rounded-2xl p-4">
                                <div class="flex items-center justify-between text-xs text-blue-50/80">
                                    <span>{{ $isPlatform ? __('Active clients') : __('Pipeline') }}</span>
                                    <span class="rounded-full bg-emerald-400/20 px-2 py-0.5 font-semibold text-emerald-200">▲</span>
                                </div>
                                <div class="mt-3 flex h-14 items-end gap-1.5">
                                    @foreach ([40, 58, 46, 72, 64, 90] as $height)
                                        <span class="auth-mini-bar flex-1 rounded-sm" style="height: {{ $height }}%; --i: {{ $loop->index }}"></span>
                                    @endforeach
                                </div>
                            </div>

                            <div class="auth-glass auth-float auth-float--b mb-3 flex items-center gap-3 rounded-2xl py-3 ps-3 pe-4">
                                <span class="flex size-9 items-center justify-center rounded-xl bg-white/15">
                                    <flux:icon :icon="$isPlatform ? 'shield-check' : 'bolt'" variant="mini" class="size-5 text-cyan-200" />
                                </span>
                                <span class="flex flex-col">
                                    <span class="text-sm font-semibold">{{ $isPlatform ? __('All systems healthy') : __('New lead captured') }}</span>
                                    <span class="text-xs text-blue-50/70">{{ __('just now') }}</span>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="relative z-10 hidden text-[11px] font-medium tracking-[0.35em] text-blue-50/70 lg:block">
                        {{ __('PLAN') }} <span class="mx-2 text-blue-50/30">|</span> {{ __('MANAGE') }} <span class="mx-2 text-blue-50/30">|</span> {{ __('GROW') }}
                    </div>
                </section>

                {{-- Form card. --}}
                <main class="auth-card relative z-20 mx-2 -mt-14 rounded-3xl border border-white/70 bg-white/95 p-6 shadow-[0_30px_80px_-24px_rgb(15_40_110/0.35)] backdrop-blur-xl sm:mx-6 sm:p-9 lg:col-span-5 lg:col-start-1 lg:row-start-1 lg:mx-0 lg:mt-0 lg:p-11 dark:border-white/10 dark:bg-zinc-900/90 dark:shadow-[0_30px_80px_-24px_rgb(0_0_0/0.7)]">
                    <div class="auth-stagger flex flex-col gap-8">
                        <div class="flex items-center gap-3">
                            <img src="{{ asset('img/logo-mark.png') }}" alt="" class="size-11" />
                            <div class="flex flex-col leading-none">
                                <span class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                                    opti<span class="bg-linear-to-r from-sky-400 to-blue-600 bg-clip-text text-transparent">fin</span>
                                </span>
                                <span @class(['mt-1 text-[10px] font-medium tracking-[0.18em] text-zinc-400 uppercase', 'max-sm:hidden' => $isPlatform])>
                                    {{ __('ERP for a smarter tomorrow') }}
                                </span>
                            </div>
                            @if ($isPlatform)
                                <span class="ms-auto inline-flex items-center gap-1 rounded-full bg-blue-50 px-2.5 py-1 text-[11px] font-semibold text-blue-700 ring-1 ring-blue-100 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-400/20">
                                    <flux:icon.shield-check variant="micro" />
                                    {{ __('Admin') }}
                                </span>
                            @endif
                            <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                        </div>

                        {{ $slot }}

                        <p class="flex items-center justify-center gap-1.5 text-xs text-zinc-400">
                            <flux:icon.lock-closed variant="micro" />
                            {{ __('Secure sign-in') }} · &copy; {{ date('Y') }} {{ config('app.name', 'Laravel') }}
                        </p>
                    </div>
                </main>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        <script>
            (function () {
                // Plain (non-Livewire) forms: show the button's busy state on submit.
                document.addEventListener('submit', function (event) {
                    var form = event.target.closest('[data-auth-form]');
                    if (!form) { return; }
                    form.setAttribute('data-submitting', '');
                    form.querySelectorAll('button[type="submit"]').forEach(function (button) { button.disabled = true; });
                });

                // Gentle pointer parallax on the brand panel (desktop pointers only).
                var panel = document.querySelector('[data-auth-parallax]');
                if (!panel || !window.matchMedia('(hover: hover) and (pointer: fine) and (prefers-reduced-motion: no-preference)').matches) { return; }
                var frame = null;
                panel.addEventListener('pointermove', function (event) {
                    if (frame) { return; }
                    frame = requestAnimationFrame(function () {
                        var rect = panel.getBoundingClientRect();
                        panel.style.setProperty('--px', ((event.clientX - rect.left) / rect.width - 0.5).toFixed(3));
                        panel.style.setProperty('--py', ((event.clientY - rect.top) / rect.height - 0.5).toFixed(3));
                        frame = null;
                    });
                });
                panel.addEventListener('pointerleave', function () {
                    panel.style.setProperty('--px', 0);
                    panel.style.setProperty('--py', 0);
                });
            })();
        </script>

        @fluxScripts
    </body>
</html>
