<?php

namespace App\Services;

use App\Mail\EmailSettingsTest;
use App\Models\PlatformSetting;
use App\Models\PlatformUser;
use App\Support\SettingsAudit;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email sending accounts managed by the Mother Admin at Admin → Settings →
 * Email settings, instead of the server's .env. Two independent accounts:
 *
 *  - USER_EMAILS: emails to users (password resets, and any future email to
 *    users). When saved, it becomes the app's default mailer.
 *  - MONITORING_ALERTS: ops alerts (OpsAlertNotifier) and Health check
 *    alerts, sent to its own recipient list through its own mailer.
 *
 * Each account only takes effect once saved; until then (or after it is
 * removed) that kind of email behaves exactly as configured in .env. The
 * accounts never borrow from each other. Stored in PlatformSetting, whose
 * value is always encrypted at rest; the password is never shown again.
 *
 * @phpstan-type MailAccount array{host: string, port: int, encryption: string, username: string, password: string, from_address: string, from_name: string, recipients?: list<string>}
 */
class MailSettings
{
    public const USER_EMAILS = 'user_emails';

    public const MONITORING_ALERTS = 'monitoring_alerts';

    public const USER_MAILER = 'platform_user';

    public const ALERTS_MAILER = 'platform_alerts';

    /**
     * How long a long-running process (a Horizon worker) keeps its loaded
     * settings before re-reading them, so a change on the page reaches it
     * within a minute without restarting it.
     */
    private const RELOAD_AFTER_SECONDS = 60;

    /**
     * @var array<string, MailAccount>|null
     */
    private ?array $accounts = null;

    private int $loadedAt = 0;

    public static function settingKey(string $purpose): string
    {
        return 'mail.'.$purpose;
    }

    /**
     * @return MailAccount|null
     */
    public function account(string $purpose): ?array
    {
        return $this->accounts()[$purpose] ?? null;
    }

    public function isConfigured(string $purpose): bool
    {
        return $this->account($purpose) !== null;
    }

    /**
     * @return list<string>
     */
    public function alertRecipients(): array
    {
        return $this->account(self::MONITORING_ALERTS)['recipients'] ?? [];
    }

    /**
     * What the settings page shows: everything except the password.
     *
     * @return array<string, mixed>|null
     */
    public function summary(string $purpose): ?array
    {
        $setting = PlatformSetting::query()->with('updatedBy:id,name')->where('key', self::settingKey($purpose))->first();
        $account = $setting?->value;

        if (! is_array($account) || ! $this->isValidAccount($account)) {
            return null;
        }

        unset($account['password']);

        return [
            ...$account,
            'updated_at' => $setting->updated_at,
            'updated_by' => $setting->updatedBy?->name,
        ];
    }

    /**
     * Saves one account. A null/empty password keeps the one already saved.
     *
     * @param  array{host: string, port: int, encryption: string, username: string, password: ?string, from_address: string, from_name: string, recipients?: list<string>}  $values
     */
    public function store(string $purpose, array $values, PlatformUser $by): void
    {
        $before = $this->account($purpose);
        $password = $values['password'];

        if ($password === null || $password === '') {
            $password = $before['password'] ?? '';
        }

        $stored = [...$values, 'password' => $password];

        PlatformSetting::query()->updateOrCreate(
            ['key' => self::settingKey($purpose)],
            ['value' => $stored, 'updated_by' => $by->id],
        );

        $this->forget();

        SettingsAudit::record(self::auditArea($purpose), 'saved', SettingsAudit::changes($before, $stored, ['password']), $by);
    }

    public function remove(string $purpose): void
    {
        PlatformSetting::query()->where('key', self::settingKey($purpose))->delete();

        $this->forget();

        SettingsAudit::record(self::auditArea($purpose), 'removed');
    }

    private static function auditArea(string $purpose): string
    {
        return $purpose === self::USER_EMAILS ? 'Email settings — User emails' : 'Email settings — Monitoring alerts';
    }

    /**
     * Registers the saved accounts as mailers. Called when the mail manager is
     * first resolved in a process, before any mailer is built. Nothing saved
     * means nothing changes: the .env mailer stays the default.
     */
    public function applyToConfig(): void
    {
        $user = $this->account(self::USER_EMAILS);

        if ($user !== null) {
            config([
                'mail.mailers.'.self::USER_MAILER => $this->mailerConfig($user),
                'mail.default' => self::USER_MAILER,
                'mail.from' => ['address' => $user['from_address'], 'name' => $user['from_name']],
            ]);
        }

        $alerts = $this->account(self::MONITORING_ALERTS);

        if ($alerts !== null) {
            config(['mail.mailers.'.self::ALERTS_MAILER => $this->mailerConfig($alerts)]);
        }
    }

    /**
     * For long-running processes: re-read the settings once they are older
     * than RELOAD_AFTER_SECONDS and, if they changed, rebuild the mailers.
     */
    public function refreshIfStale(): void
    {
        if ($this->accounts === null || time() - $this->loadedAt < self::RELOAD_AFTER_SECONDS) {
            return;
        }

        $before = $this->accounts;
        $this->forget();

        if ($this->accounts() !== $before) {
            $this->applyToConfig();

            try {
                Mail::purge(self::USER_MAILER);
                Mail::purge(self::ALERTS_MAILER);
            } catch (Throwable) {
                // Nothing was built yet — the next send builds it fresh.
            }
        }
    }

    /**
     * Sends a short test message through one account, without saving anything
     * else. Throws on any delivery problem so the page can show it.
     */
    public function sendTest(string $purpose, string $to): void
    {
        $mailer = $purpose === self::USER_EMAILS ? self::USER_MAILER : self::ALERTS_MAILER;

        $this->applyToConfig();
        Mail::purge($mailer);

        Mail::mailer($mailer)->to($to)->send(new EmailSettingsTest($purpose));
    }

    /**
     * @param  MailAccount  $account
     * @return array<string, mixed>
     */
    public function mailerConfig(array $account): array
    {
        return [
            'transport' => 'smtp',
            // smtps = implicit TLS (usually port 465); smtp upgrades with
            // STARTTLS when the server offers it (usually port 587).
            'scheme' => $account['encryption'] === 'ssl' ? 'smtps' : 'smtp',
            'host' => $account['host'],
            'port' => $account['port'],
            'username' => $account['username'],
            'password' => $account['password'],
            'timeout' => 15,
            'local_domain' => config('mail.mailers.smtp.local_domain'),
            'from' => ['address' => $account['from_address'], 'name' => $account['from_name']],
        ];
    }

    /**
     * @return array<string, MailAccount>
     */
    private function accounts(): array
    {
        if ($this->accounts !== null) {
            return $this->accounts;
        }

        $accounts = [];

        try {
            $settings = PlatformSetting::query()
                ->whereIn('key', [self::settingKey(self::USER_EMAILS), self::settingKey(self::MONITORING_ALERTS)])
                ->get();

            foreach ($settings as $setting) {
                $value = $setting->value;

                if (is_array($value) && $this->isValidAccount($value)) {
                    $accounts[substr($setting->key, strlen('mail.'))] = $value;
                }
            }
        } catch (Throwable) {
            // Table missing (fresh install before migrating) or database down:
            // behave as if nothing is saved, i.e. exactly as .env says.
            $accounts = [];
        }

        $this->loadedAt = time();

        return $this->accounts = $accounts;
    }

    private function forget(): void
    {
        $this->accounts = null;
    }

    /**
     * @param  array<mixed>  $account
     *
     * @phpstan-assert-if-true MailAccount $account
     */
    private function isValidAccount(array $account): bool
    {
        foreach (['host', 'encryption', 'username', 'password', 'from_address', 'from_name'] as $field) {
            if (! is_string($account[$field] ?? null)) {
                return false;
            }
        }

        return is_int($account['port'] ?? null);
    }
}
