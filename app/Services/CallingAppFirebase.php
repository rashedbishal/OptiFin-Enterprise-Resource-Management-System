<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\PlatformUser;
use Illuminate\Support\Carbon;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Factory;
use RuntimeException;

/**
 * The WhatsApp calling app's Firebase service account — ONE platform-wide
 * secret (every company's agents use the same OptiFin Calling app, which can
 * only be woken by its own Firebase project), uploaded by the Mother Admin in
 * Admin → Calling app and stored encrypted in platform_settings. Never in
 * .env; replacing it takes effect on the very next push (no restart, no
 * cached client — see AppServiceProvider's Messaging binding).
 *
 * Not configured = no app pushes are sent and a paired app does NOT count as
 * available (SoftphonePresence), so incoming calls keep ringing in the
 * browser exactly as before.
 */
class CallingAppFirebase
{
    public const SETTING_KEY = 'calling_app.firebase_service_account';

    /**
     * Per-instance memo (this class is never a singleton, so a long-running
     * queue worker re-reads the key for every job).
     *
     * @var array<string, mixed>|null|false false = not loaded yet
     */
    private array|null|false $loaded = false;

    public function isConfigured(): bool
    {
        return $this->stored() !== null;
    }

    /**
     * Non-secret facts for the admin page: project, service account email,
     * when and by whom it was uploaded. Never the private key.
     *
     * @return array{project_id: string, client_email: string, uploaded_at: Carbon|null, uploaded_by: string|null}|null
     */
    public function summary(): ?array
    {
        $setting = PlatformSetting::query()->with('updatedBy:id,name')->where('key', self::SETTING_KEY)->first();
        $account = $setting?->value;

        if (! is_array($account)) {
            return null;
        }

        return [
            'project_id' => (string) ($account['project_id'] ?? ''),
            'client_email' => (string) ($account['client_email'] ?? ''),
            'uploaded_at' => $setting->updated_at,
            'uploaded_by' => $setting->updatedBy?->name,
        ];
    }

    /**
     * Checks an uploaded service-account JSON. Returns an error message, or
     * null when it is usable for the calling app's Firebase project.
     */
    public static function validationError(mixed $account): ?string
    {
        if (! is_array($account)
            || ($account['type'] ?? null) !== 'service_account'
            || ! is_string($account['project_id'] ?? null)
            || ! is_string($account['client_email'] ?? null)
            || ! is_string($account['private_key'] ?? null)
            || ! str_contains($account['private_key'], 'PRIVATE KEY')) {
            return __('This is not a Firebase service account key file. In Firebase: Project settings → Service accounts → Generate new private key.');
        }

        $expected = (string) config('calling.calling_app.firebase_project_id');

        if ($expected !== '' && $account['project_id'] !== $expected) {
            return __('This key belongs to Firebase project ":actual", but the OptiFin Calling app uses ":expected".', [
                'actual' => $account['project_id'],
                'expected' => $expected,
            ]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $account  already validated (validationError() === null)
     */
    public function store(array $account, PlatformUser $by): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => $account, 'updated_by' => $by->id],
        );

        $this->loaded = false;
    }

    public function remove(): void
    {
        PlatformSetting::query()->where('key', self::SETTING_KEY)->delete();
        $this->loaded = false;
    }

    /**
     * A Firebase Messaging client built from the stored key — fresh on every
     * call, so a replaced key is used immediately even by a long-running
     * queue worker.
     */
    public function messaging(): Messaging
    {
        $account = $this->stored();

        if ($account === null) {
            throw new RuntimeException('The calling app Firebase key is not configured (Admin → Calling app).');
        }

        return (new Factory)->withServiceAccount($account)->createMessaging();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stored(): ?array
    {
        if ($this->loaded === false) {
            $value = PlatformSetting::query()->where('key', self::SETTING_KEY)->first()?->value;
            $this->loaded = is_array($value) ? $value : null;
        }

        return $this->loaded;
    }
}
