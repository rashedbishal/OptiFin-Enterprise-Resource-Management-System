<?php

namespace App\Livewire\Platform\Settings;

use App\Models\PlatformUser;
use App\Services\MailSettings;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Admin → Settings → Email settings: the Mother Admin manages the two email
 * sending accounts (App\Services\MailSettings) — "User emails" (password
 * resets) and "Monitoring alerts" (ops + Health alerts, to a recipient list).
 * Each only takes effect once saved; until then that kind of email behaves as
 * the server's .env says. Mother Admin only, like the Calling app page: one
 * account affects every company.
 */
#[Title('Email settings')]
#[Layout('layouts.admin')]
class Email extends Component
{
    private const MAX_RECIPIENTS = 20;

    /**
     * Form state for the "User emails" account. Client-editable, so it is
     * only trusted after validatedAccount().
     *
     * @var array<string, mixed>
     */
    public array $user = [];

    /**
     * Form state for the "Monitoring alerts" account, plus `recipients`
     * (one address per line). Only trusted after validation.
     *
     * @var array<string, mixed>
     */
    public array $alerts = [];

    public string $userTestTo = '';

    #[Locked]
    public ?string $confirmingRemove = null;

    public bool $showRemoveModal = false;

    public function mount(): void
    {
        $actor = $this->authorizeMotherAdmin();

        $this->user = $this->formFor(MailSettings::USER_EMAILS);
        $this->alerts = [...$this->formFor(MailSettings::MONITORING_ALERTS), 'recipients' => implode("\n", $this->summaries()[MailSettings::MONITORING_ALERTS]['recipients'] ?? [])];
        $this->userTestTo = $actor->email;
    }

    /**
     * @return array<string, array<string, mixed>|null>
     */
    #[Computed]
    public function summaries(): array
    {
        $settings = app(MailSettings::class);

        return [
            MailSettings::USER_EMAILS => $settings->summary(MailSettings::USER_EMAILS),
            MailSettings::MONITORING_ALERTS => $settings->summary(MailSettings::MONITORING_ALERTS),
        ];
    }

    public function saveUser(): void
    {
        $actor = $this->authorizeMotherAdmin();
        $values = $this->validatedAccount('user', MailSettings::USER_EMAILS);

        app(MailSettings::class)->store(MailSettings::USER_EMAILS, $values, $actor);
        $this->user['password'] = '';
        unset($this->summaries);

        Flux::toast(variant: 'success', text: __('User email account saved. Password reset emails are now sent through it.'));
    }

    public function saveAlerts(): void
    {
        $actor = $this->authorizeMotherAdmin();
        $values = $this->validatedAccount('alerts', MailSettings::MONITORING_ALERTS);
        $values['recipients'] = $this->validatedRecipients();

        app(MailSettings::class)->store(MailSettings::MONITORING_ALERTS, $values, $actor);
        $this->alerts['password'] = '';
        $this->alerts['recipients'] = implode("\n", $values['recipients']);
        unset($this->summaries);

        Flux::toast(variant: 'success', text: __('Monitoring alert account saved. Alerts are now sent through it.'));
    }

    public function sendUserTest(): void
    {
        $this->authorizeMotherAdmin();

        $this->validate(['userTestTo' => ['required', 'email', 'max:255']], [], ['userTestTo' => __('test address')]);

        $this->sendTest(MailSettings::USER_EMAILS, $this->userTestTo);
    }

    public function sendAlertsTest(): void
    {
        $this->authorizeMotherAdmin();

        $this->sendTest(MailSettings::MONITORING_ALERTS, app(MailSettings::class)->alertRecipients());
    }

    public function confirmRemove(string $purpose): void
    {
        $this->authorizeMotherAdmin();

        if (! in_array($purpose, [MailSettings::USER_EMAILS, MailSettings::MONITORING_ALERTS], true)) {
            return;
        }

        $this->confirmingRemove = $purpose;
        $this->showRemoveModal = true;
    }

    public function remove(): void
    {
        $this->authorizeMotherAdmin();

        if ($this->confirmingRemove !== null) {
            app(MailSettings::class)->remove($this->confirmingRemove);

            if ($this->confirmingRemove === MailSettings::USER_EMAILS) {
                $this->user = $this->formFor(MailSettings::USER_EMAILS, ignoreSaved: true);
            } else {
                $this->alerts = [...$this->formFor(MailSettings::MONITORING_ALERTS, ignoreSaved: true), 'recipients' => ''];
            }
        }

        $this->showRemoveModal = false;
        $this->confirmingRemove = null;
        unset($this->summaries);

        Flux::toast(variant: 'success', text: __('Email account removed. That kind of email is back to the server\'s default setting.'));
    }

    /**
     * @param  string|list<string>  $to
     */
    private function sendTest(string $purpose, string|array $to): void
    {
        if (! app(MailSettings::class)->isConfigured($purpose)) {
            Flux::toast(variant: 'warning', text: __('Save this account first, then send a test.'));

            return;
        }

        if ($to === [] || $to === '') {
            Flux::toast(variant: 'warning', text: __('Add at least one alert recipient first.'));

            return;
        }

        try {
            foreach ((array) $to as $address) {
                app(MailSettings::class)->sendTest($purpose, $address);
            }
        } catch (Throwable $exception) {
            // The SMTP server's own answer is what the admin needs to fix it
            // (wrong password, blocked port…); it never contains the password.
            Flux::toast(variant: 'danger', duration: 12000, text: __('The test email could not be sent: :error', ['error' => Str::limit($exception->getMessage(), 300)]));

            return;
        }

        Flux::toast(variant: 'success', text: trans_choice('Test email sent to :to. Check that inbox (and its spam folder).|Test email sent to :count addresses. Check those inboxes (and spam folders).', count((array) $to), ['to' => implode(', ', (array) $to), 'count' => count((array) $to)]));
    }

    /**
     * @return array{host: string, port: int, encryption: string, username: string, password: ?string, from_address: string, from_name: string}
     */
    private function validatedAccount(string $field, string $purpose): array
    {
        $alreadySaved = app(MailSettings::class)->isConfigured($purpose);

        $validated = $this->validate([
            "{$field}.host" => ['required', 'string', 'max:255'],
            "{$field}.port" => ['required', 'integer', 'between:1,65535'],
            "{$field}.encryption" => ['required', Rule::in(['tls', 'ssl', 'none'])],
            "{$field}.username" => ['required', 'string', 'max:255'],
            "{$field}.password" => [$alreadySaved ? 'nullable' : 'required', 'string', 'max:255'],
            "{$field}.from_address" => ['required', 'email', 'max:255'],
            "{$field}.from_name" => ['required', 'string', 'max:100'],
        ], [], [
            "{$field}.host" => __('SMTP host'),
            "{$field}.port" => __('port'),
            "{$field}.encryption" => __('encryption'),
            "{$field}.username" => __('username'),
            "{$field}.password" => __('password'),
            "{$field}.from_address" => __('sender address'),
            "{$field}.from_name" => __('sender name'),
        ])[$field];

        return [
            'host' => trim((string) $validated['host']),
            'port' => (int) $validated['port'],
            'encryption' => (string) $validated['encryption'],
            'username' => trim((string) $validated['username']),
            // Gmail shows app passwords in groups of four; spaces aren't part of it.
            'password' => filled($validated['password'] ?? null) ? str_replace(' ', '', (string) $validated['password']) : null,
            'from_address' => trim((string) $validated['from_address']),
            'from_name' => trim((string) $validated['from_name']),
        ];
    }

    /**
     * One address per line, or separated by commas.
     *
     * @return list<string>
     */
    private function validatedRecipients(): array
    {
        $addresses = array_values(array_unique(array_filter(
            preg_split('/[\s,;]+/', (string) ($this->alerts['recipients'] ?? '')) ?: [],
            fn (string $address): bool => $address !== '',
        )));

        if ($addresses === []) {
            throw ValidationException::withMessages(['alerts.recipients' => __('Add at least one email address to send alerts to.')]);
        }

        if (count($addresses) > self::MAX_RECIPIENTS) {
            throw ValidationException::withMessages(['alerts.recipients' => __('Use at most :max addresses.', ['max' => self::MAX_RECIPIENTS])]);
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationException::withMessages(['alerts.recipients' => __('":address" is not a valid email address.', ['address' => $address])]);
            }
        }

        return $addresses;
    }

    /**
     * The form's starting values: the saved account (without its password),
     * or Gmail's settings so a Gmail account only needs address + password.
     *
     * @return array{host: string, port: int|string, encryption: string, username: string, password: string, from_address: string, from_name: string}
     */
    private function formFor(string $purpose, bool $ignoreSaved = false): array
    {
        $saved = $ignoreSaved ? null : $this->summaries()[$purpose];

        return [
            'host' => (string) ($saved['host'] ?? 'smtp.gmail.com'),
            'port' => $saved['port'] ?? 587,
            'encryption' => (string) ($saved['encryption'] ?? 'tls'),
            'username' => (string) ($saved['username'] ?? ''),
            'password' => '',
            'from_address' => (string) ($saved['from_address'] ?? ''),
            'from_name' => (string) ($saved['from_name'] ?? config('app.name')),
        ];
    }

    private function authorizeMotherAdmin(): PlatformUser
    {
        $actor = Auth::guard('platform')->user();

        abort_unless($actor instanceof PlatformUser && $actor->isMotherAdmin(), 403);

        return $actor;
    }
}
