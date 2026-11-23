<x-layouts::auth.showcase :title="__('Reset password')">
    <div class="flex flex-col gap-7">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 sm:text-[1.7rem] dark:text-white">{{ __('Choose a new password') }}</h1>
            <p class="mt-1.5 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Pick something strong that you haven\'t used before.') }}</p>
        </div>

        <x-auth-session-status class="rounded-xl bg-emerald-50 px-4 py-3 dark:bg-emerald-500/10" :status="session('status')" />

        <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-5" data-auth-form>
            @csrf
            <input type="hidden" name="token" value="{{ request()->route('token') }}">

            <flux:input
                name="email"
                value="{{ request('email') }}"
                :label="__('Email address')"
                type="email"
                icon="envelope"
                required
                autocomplete="email"
            />

            <flux:input
                name="password"
                :label="__('New password')"
                type="password"
                icon="lock-closed"
                required
                autofocus
                autocomplete="new-password"
                :placeholder="__('Enter a new password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <flux:input
                name="password_confirmation"
                :label="__('Confirm new password')"
                type="password"
                icon="lock-closed"
                required
                autocomplete="new-password"
                :placeholder="__('Repeat the new password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <button type="submit" class="auth-submit mt-2" data-test="reset-password-button">
                <span class="auth-submit-label">{{ __('Reset password') }}</span>
                <flux:icon.arrow-right variant="mini" class="auth-submit-arrow" />
                <svg class="auth-submit-spinner" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            </button>
        </form>

        <a href="{{ route('login') }}" class="group inline-flex items-center justify-center gap-1.5 text-sm text-zinc-500 dark:text-zinc-400" data-auth-aside>
            <flux:icon.arrow-left variant="micro" class="transition-transform duration-300 group-hover:-translate-x-0.5" />
            <span>{{ __('Back to') }} <span class="auth-link font-medium">{{ __('sign in') }}</span></span>
        </a>
    </div>
</x-layouts::auth.showcase>
