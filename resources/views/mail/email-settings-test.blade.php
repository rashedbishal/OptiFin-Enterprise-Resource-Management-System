<x-mail::message>
# {{ __('Email settings work') }}

{{ __('This is a test from :app. The ":account" sending account is set up correctly — emails sent through it will arrive like this one.', ['app' => config('app.name'), 'account' => $accountLabel]) }}

{{ __('No action is needed.') }}
</x-mail::message>
