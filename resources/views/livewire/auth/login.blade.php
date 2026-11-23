<x-layouts::auth.showcase :title="__('Log in')">
    <div class="flex flex-col gap-7">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 sm:text-[1.7rem] dark:text-white">{{ __('Nice to see you again') }}</h1>
            <p class="mt-1.5 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Sign in to your workspace to pick up where you left off.') }}</p>
        </div>

        <x-auth-session-status class="rounded-xl bg-emerald-50 px-4 py-3 dark:bg-emerald-500/10" :status="session('status')" />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-5" data-auth-form>
            @csrf

            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                icon="envelope"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <flux:field>
                <div class="flex items-center justify-between gap-3">
                    <flux:label>{{ __('Password') }}</flux:label>
                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}" class="auth-link text-sm font-medium">{{ __('Forgot password?') }}</a>
                    @endif
                </div>
                <flux:input
                    name="password"
                    type="password"
                    icon="lock-closed"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Enter your password')"
                    viewable
                />
                <flux:error name="password" />
            </flux:field>

            <button type="submit" class="auth-submit mt-2" data-test="login-button">
                <span class="auth-submit-label">{{ __('Sign in') }}</span>
                <flux:icon.arrow-right variant="mini" class="auth-submit-arrow" />
                <svg class="auth-submit-spinner" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            </button>
        </form>
    </div>
</x-layouts::auth.showcase>
