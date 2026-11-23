<div class="flex flex-col gap-7">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 sm:text-[1.7rem] dark:text-white">{{ __('Welcome back, admin') }}</h1>
        <p class="mt-1.5 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Sign in to the platform console to manage every client workspace.') }}</p>
    </div>

    <x-auth-session-status class="rounded-xl bg-emerald-50 px-4 py-3 dark:bg-emerald-500/10" :status="session('status')" />

    <form wire:submit="authenticate" class="flex flex-col gap-5">
        <flux:input
            wire:model="email"
            :label="__('Email address')"
            type="email"
            icon="envelope"
            required
            autofocus
            autocomplete="email"
            placeholder="email@example.com"
        />

        <flux:input
            wire:model="password"
            :label="__('Password')"
            type="password"
            icon="lock-closed"
            required
            autocomplete="current-password"
            :placeholder="__('Enter your password')"
            viewable
        />

        <button
            type="submit"
            class="auth-submit mt-2"
            wire:loading.attr="disabled"
            wire:target="authenticate"
            data-test="login-button"
        >
            <span class="auth-submit-label">{{ __('Sign in') }}</span>
            <flux:icon.arrow-right variant="mini" class="auth-submit-arrow" />
            <svg class="auth-submit-spinner" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
        </button>
    </form>
</div>
