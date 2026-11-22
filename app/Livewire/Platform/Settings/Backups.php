<?php

namespace App\Livewire\Platform\Settings;

use App\Jobs\RunBackup;
use App\Models\PlatformUser;
use App\Services\BackupSettings;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Admin → Settings → Backups (Mother Admin only): the Backblaze B2 storage
 * and archive password for the nightly database + uploaded-files backups
 * (App\Services\BackupSettings), plus the list of backups with back up now,
 * download and delete.
 */
#[Title('Backups')]
#[Layout('layouts.admin')]
class Backups extends Component
{
    /**
     * Keep refreshing the list for this long after "Back up now", so the new
     * backup appears without a manual reload (but the page isn't listing B2
     * forever while left open).
     */
    private const WATCH_SECONDS = 180;

    public string $keyId = '';

    public string $applicationKey = '';

    public string $bucket = '';

    public string $endpoint = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    #[Locked]
    public int $watchUntil = 0;

    #[Locked]
    public ?string $confirmingDeletePath = null;

    public bool $showDeleteModal = false;

    public bool $showRemoveModal = false;

    public function mount(): void
    {
        $this->authorizeMotherAdmin();

        $summary = $this->summary();

        if ($summary !== null) {
            $this->keyId = $summary['key_id'];
            $this->bucket = $summary['bucket'];
            $this->endpoint = $summary['endpoint'];
        }
    }

    /**
     * @return array{key_id: string, bucket: string, endpoint: string, updated_at: mixed, updated_by: string|null}|null
     */
    #[Computed]
    public function summary(): ?array
    {
        return app(BackupSettings::class)->summary();
    }

    /**
     * Live B2 listing, or an error message when B2 can't be reached.
     *
     * @return array{backups: Collection<int, array{path: string, date: CarbonInterface, size: int}>, error: string|null}
     */
    #[Computed]
    public function listing(): array
    {
        $settings = app(BackupSettings::class);

        if (! $settings->isConfigured()) {
            return ['backups' => collect(), 'error' => null];
        }

        try {
            return ['backups' => $settings->backups(), 'error' => null];
        } catch (Throwable $exception) {
            report($exception);

            return ['backups' => collect(), 'error' => Str::limit($exception->getMessage(), 300)];
        }
    }

    #[Computed]
    public function watching(): bool
    {
        return $this->watchUntil > time();
    }

    public function save(): void
    {
        $actor = $this->authorizeMotherAdmin();
        $alreadySaved = app(BackupSettings::class)->isConfigured();

        $validated = $this->validate([
            'keyId' => ['required', 'string', 'max:255'],
            'applicationKey' => [$alreadySaved ? 'nullable' : 'required', 'string', 'max:255'],
            'bucket' => ['required', 'string', 'max:63', 'regex:/^[A-Za-z0-9-]+$/'],
            'endpoint' => ['required', 'string', 'max:255', 'regex:/^s3\.[a-z0-9-]+\.backblazeb2\.com$/'],
            'password' => [$alreadySaved ? 'nullable' : 'required', 'string', 'min:12', 'max:255', 'same:passwordConfirmation'],
        ], [
            'endpoint.regex' => __('Paste the "Endpoint" shown on the bucket page, e.g. s3.us-west-004.backblazeb2.com (no https://).'),
            'password.same' => __('The two passwords don\'t match.'),
        ], [
            'keyId' => __('key ID'),
            'applicationKey' => __('application key'),
            'bucket' => __('bucket name'),
            'endpoint' => __('endpoint'),
            'password' => __('backup password'),
        ]);

        app(BackupSettings::class)->store([
            'key_id' => trim($validated['keyId']),
            'application_key' => filled($validated['applicationKey'] ?? null) ? trim((string) $validated['applicationKey']) : null,
            'bucket' => trim($validated['bucket']),
            'endpoint' => strtolower(trim($validated['endpoint'])),
            'password' => filled($validated['password'] ?? null) ? (string) $validated['password'] : null,
        ], $actor);

        $this->reset('applicationKey', 'password', 'passwordConfirmation');
        unset($this->summary, $this->listing);

        Flux::toast(variant: 'success', text: __('Backup settings saved. Use "Test connection" to check them.'));
    }

    public function testConnection(): void
    {
        $this->authorizeMotherAdmin();

        if (! app(BackupSettings::class)->isConfigured()) {
            Flux::toast(variant: 'warning', text: __('Save the settings first, then test them.'));

            return;
        }

        try {
            app(BackupSettings::class)->testConnection();
        } catch (Throwable $exception) {
            Flux::toast(variant: 'danger', duration: 12000, text: __('Backblaze B2 refused the connection: :error', ['error' => Str::limit($exception->getMessage(), 300)]));

            return;
        }

        Flux::toast(variant: 'success', text: __('Connected to Backblaze B2. Backups can be stored there.'));
    }

    public function backUpNow(): void
    {
        $this->authorizeMotherAdmin();

        if (! app(BackupSettings::class)->isConfigured()) {
            Flux::toast(variant: 'warning', text: __('Save the settings first.'));

            return;
        }

        RunBackup::dispatch();
        $this->watchUntil = time() + self::WATCH_SECONDS;

        Flux::toast(variant: 'success', text: __('Backup started. It will appear in the list below in a minute or so.'));
    }

    /**
     * Re-lists B2 while a "Back up now" may still be running (wire:poll).
     */
    public function refreshListing(): void
    {
        $this->authorizeMotherAdmin();

        unset($this->listing);
    }

    public function download(string $path): ?StreamedResponse
    {
        $this->authorizeMotherAdmin();

        $response = app(BackupSettings::class)->download($path);

        if ($response === null) {
            Flux::toast(variant: 'danger', text: __('That backup no longer exists.'));
        }

        return $response;
    }

    public function confirmDelete(string $path): void
    {
        $this->authorizeMotherAdmin();

        $this->confirmingDeletePath = $path;
        $this->showDeleteModal = true;
    }

    public function deleteConfirmed(): void
    {
        $this->authorizeMotherAdmin();

        $deleted = $this->confirmingDeletePath !== null && app(BackupSettings::class)->delete($this->confirmingDeletePath);

        $this->showDeleteModal = false;
        $this->confirmingDeletePath = null;
        unset($this->listing);

        Flux::toast(variant: $deleted ? 'success' : 'danger', text: $deleted ? __('Backup deleted.') : __('That backup no longer exists.'));
    }

    public function confirmRemove(): void
    {
        $this->authorizeMotherAdmin();

        $this->showRemoveModal = true;
    }

    /**
     * Removes the saved settings only — backups already on B2 stay there.
     */
    public function remove(): void
    {
        $this->authorizeMotherAdmin();

        app(BackupSettings::class)->remove();

        $this->reset('keyId', 'applicationKey', 'bucket', 'endpoint', 'password', 'passwordConfirmation');
        $this->showRemoveModal = false;
        unset($this->summary, $this->listing);

        Flux::toast(variant: 'success', text: __('Backup settings removed. Nightly backups are now switched off; existing backups stay on Backblaze B2.'));
    }

    private function authorizeMotherAdmin(): PlatformUser
    {
        $actor = Auth::guard('platform')->user();

        abort_unless($actor instanceof PlatformUser && $actor->isMotherAdmin(), 403);

        return $actor;
    }
}
