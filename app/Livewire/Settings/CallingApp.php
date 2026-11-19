<?php

namespace App\Livewire\Settings;

use App\Actions\Tenant\IssueCallingAppPairingCode;
use App\Actions\Tenant\UnpairCallingAppDevice;
use App\Models\CallingAppDevice;
use App\Models\TenantUser;
use App\Services\CallingAppRelease;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings → Calling app: the logged-in user pairs their phone with the
 * WhatsApp calling companion app (one phone per user) or unpairs it. The
 * app has no login of its own — "Connect" mints a one-time code and opens
 * the app through its deep link; the app redeems the code itself.
 */
#[Title('Calling app settings')]
class CallingApp extends Component
{
    /**
     * The deep link for the most recently issued pairing code — rendered as
     * a manual "Open the app" fallback in case the automatic redirect was
     * blocked by the browser. Never persisted anywhere.
     */
    public ?string $pairingLink = null;

    public bool $showUnpairModal = false;

    public function connect(IssueCallingAppPairingCode $action): void
    {
        $issued = $action->handle($this->user());

        $this->pairingLink = $issued['deep_link'];

        // The view stores the marker in this browser's localStorage BEFORE
        // opening the app — that's how this phone's browser later knows to
        // hand calls to the app it just paired.
        $this->dispatch('calling-app-open', link: $issued['deep_link'], marker: $issued['browser_marker']);
    }

    public function confirmUnpair(): void
    {
        $this->showUnpairModal = true;
    }

    public function unpair(UnpairCallingAppDevice $action): void
    {
        $this->showUnpairModal = false;

        $device = $this->device();

        if ($device !== null) {
            $action->handle($device);
        }

        unset($this->device);

        Flux::toast(variant: 'success', text: __('Calling app unpaired.'));
    }

    /**
     * The downloadable app, when one is uploaded AND this user's company has
     * WhatsApp calling switched on (CallingAppRelease::tenantMayDownload).
     *
     * @return array{version: string, notes: string|null, size: int, sha256: string, path: string, uploaded_at: Carbon|null, uploaded_by: string|null}|null
     */
    #[Computed]
    public function release(): ?array
    {
        return CallingAppRelease::tenantMayDownload($this->user()) ? app(CallingAppRelease::class)->current() : null;
    }

    #[Computed]
    public function device(): ?CallingAppDevice
    {
        return CallingAppDevice::query()->where('tenant_user_id', $this->user()->id)->first();
    }

    private function user(): TenantUser
    {
        $user = Auth::guard('web')->user();

        abort_unless($user instanceof TenantUser, 403);

        return $user;
    }
}
