<?php

namespace App\Livewire\Platform\Settings;

use App\Models\CallingAppDevice;
use App\Models\PlatformUser;
use App\Models\Tenant;
use App\Services\CallingAppFirebase;
use Flux\Flux;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Admin → Calling app: the Mother Admin uploads the OptiFin Calling app's
 * Firebase service-account key — one platform-wide secret, since every
 * company's agents use the same app, which only its own Firebase project can
 * wake. Stored encrypted (CallingAppFirebase); its private key is never shown
 * again. Each company's own WhatsApp settings stay on their lead sources.
 *
 * Mother Admin only, like permanent tenant deletion: one key affects every
 * company's incoming calls.
 */
#[Title('Calling app')]
#[Layout('layouts.admin')]
class CallingApp extends Component
{
    use WithFileUploads;

    /** @var UploadedFile|null */
    public $keyFile = null;

    public bool $showRemoveModal = false;

    public ?string $testDeviceId = null;

    public function mount(): void
    {
        $this->authorizeMotherAdmin();
    }

    public function save(CallingAppFirebase $firebase): void
    {
        $actor = $this->authorizeMotherAdmin();

        $this->validate([
            'keyFile' => ['required', 'file', 'max:64', 'extensions:json'],
        ], [], ['keyFile' => __('key file')]);

        $account = json_decode((string) file_get_contents($this->keyFile->getRealPath()), true);
        $error = CallingAppFirebase::validationError($account);

        if ($error !== null) {
            throw ValidationException::withMessages(['keyFile' => $error]);
        }

        $firebase->store($account, $actor);
        $this->reset('keyFile');
        unset($this->summary);

        Flux::toast(variant: 'success', text: __('Firebase key saved. Incoming calls can now ring the calling app.'));
    }

    public function confirmRemove(): void
    {
        $this->authorizeMotherAdmin();
        $this->showRemoveModal = true;
    }

    public function remove(CallingAppFirebase $firebase): void
    {
        $this->authorizeMotherAdmin();
        $this->showRemoveModal = false;

        $firebase->remove();
        unset($this->summary);

        Flux::toast(variant: 'success', text: __('Firebase key removed. Incoming calls ring in the browser only.'));
    }

    /**
     * Sends a visible test notification to one paired phone — proves the
     * key, the project and that phone's push registration in one go.
     */
    public function sendTest(CallingAppFirebase $firebase): void
    {
        $this->authorizeMotherAdmin();

        $device = CallingAppDevice::query()->whereNotNull('fcm_token')->find($this->testDeviceId);

        $token = $device?->fcm_token;

        if ($token === null || $token === '' || ! $firebase->isConfigured()) {
            Flux::toast(variant: 'danger', text: __('Choose a phone, and upload the Firebase key first.'));

            return;
        }

        try {
            $firebase->messaging()->send(
                CloudMessage::new()
                    ->toToken($token)
                    ->withNotification(Notification::create(__('OptiFin Calling test'), __('Push notifications work on this phone.')))
                    ->withData(['type' => 'test'])
            );

            Flux::toast(variant: 'success', text: __('Test sent. It should appear on the phone within a few seconds.'));
        } catch (Throwable $exception) {
            Flux::toast(variant: 'danger', text: __('Firebase refused the test: :message', ['message' => mb_substr($exception->getMessage(), 0, 200)]));
        }
    }

    /**
     * @return array{project_id: string, client_email: string, uploaded_at: Carbon|null, uploaded_by: string|null}|null
     */
    #[Computed]
    public function summary(): ?array
    {
        return app(CallingAppFirebase::class)->summary();
    }

    /**
     * Paired phones that can receive pushes, for the test dropdown.
     *
     * @return Collection<int, array{value: string, label: string}>
     */
    #[Computed]
    public function testDevices(): Collection
    {
        return CallingAppDevice::query()
            ->whereNotNull('fcm_token')
            ->with(['tenantUser:id,name,tenant_id', 'tenantUser.tenant:id,name'])
            ->latest('last_seen_at')
            ->limit(200)
            ->get()
            ->map(function (CallingAppDevice $device): array {
                $tenant = $device->tenantUser->tenant;

                return [
                    'value' => $device->id,
                    'label' => implode(' · ', array_filter([
                        $tenant instanceof Tenant ? $tenant->name : null,
                        $device->tenantUser->name,
                        $device->device_name,
                    ])),
                ];
            });
    }

    private function authorizeMotherAdmin(): PlatformUser
    {
        $actor = Auth::guard('platform')->user();

        abort_unless($actor instanceof PlatformUser && $actor->isMotherAdmin(), 403);

        return $actor;
    }
}
