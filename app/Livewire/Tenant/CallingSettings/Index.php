<?php

namespace App\Livewire\Tenant\CallingSettings;

use App\Enums\LeadSourceType;
use App\Models\LeadSource;
use App\Services\OutboundMediaStorage;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * WhatsApp inbound calling — lets a Client Admin upload a tenant-wide custom
 * ringtone (MP3, <=1MB) played by resources/js/softphone/ringtone.js instead
 * of the default synthesized tone. Tenant-wide by design (not per-user) —
 * same scope as the existing meeting/follow-up alert settings on this same
 * `tenants` row, deliberately simpler than giving every Client User their
 * own pick.
 *
 * Whole-page Client-Admin-gated via isClientAdmin(), same bar
 * toggleMeetingAlert() uses for a direct tenant-settings mutation — there is
 * no TenantUser-facing Policy for "update my own tenant" (TenantPolicy is
 * Platform-side only, typed PlatformUser), so this mirrors that existing
 * raw-conditional convention rather than inventing a new Policy class for a
 * single settings page.
 */
#[Title('Calling Settings')]
class Index extends Component
{
    use WithFileUploads;

    private const int MAX_FILE_SIZE_KB = 1024; // 1 MB

    private const string DISK = 'local';

    private const string DIRECTORY = 'whatsapp-call-ringtones';

    public ?TemporaryUploadedFile $ringtoneFile = null;

    /**
     * Mirrors the Lead Management hub's own tile gating (Dashboard::
     * hasWhatsAppSource()) — without a WhatsApp lead source, WhatsApp
     * calling can never happen, so a ringtone for it is a dead end. The
     * admin check runs first so a non-admin still gets a 403, not a
     * redirect, matching this page's pre-existing authorization contract.
     */
    public function mount(): void
    {
        abort_unless(Auth::user()->isClientAdmin(), 403);

        if (! $this->tenantHasWhatsAppSource()) {
            $this->redirect(route('lead-management.index'), navigate: true);
        }
    }

    private function tenantHasWhatsAppSource(): bool
    {
        return LeadSource::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->where('type', LeadSourceType::Whatsapp)
            ->exists();
    }

    public function humanSize(?int $bytes): ?string
    {
        return $bytes === null ? null : OutboundMediaStorage::humanSize($bytes);
    }

    /**
     * Replaces whatever ringtone (if any) the tenant already had — the old
     * file is deleted from storage only after the new one is safely stored
     * and the tenant row updated, so a mid-request failure never leaves the
     * tenant with no file on disk for a path it still references.
     */
    public function save(): void
    {
        abort_unless(Auth::user()->isClientAdmin(), 403);

        $this->validate([
            'ringtoneFile' => ['required', 'file', 'mimes:mp3', 'max:'.self::MAX_FILE_SIZE_KB],
        ]);

        $tenant = Auth::user()->tenant;
        $previousPath = $tenant->whatsapp_call_ringtone_path;

        $storedPath = $this->ringtoneFile->storeAs(self::DIRECTORY, Str::ulid().'.mp3', self::DISK);

        $tenant->update([
            'whatsapp_call_ringtone_path' => $storedPath,
            'whatsapp_call_ringtone_original_name' => $this->ringtoneFile->getClientOriginalName(),
            'whatsapp_call_ringtone_size' => $this->ringtoneFile->getSize(),
        ]);

        if ($previousPath !== null) {
            Storage::disk(self::DISK)->delete($previousPath);
        }

        $this->reset('ringtoneFile');
        Flux::toast(variant: 'success', text: __('Ringtone saved.'));
    }

    /**
     * Reverts to the default synthesized tone — deletes the stored file and
     * clears all three columns together (see the migration's own docblock:
     * they're never left partially set).
     */
    public function remove(): void
    {
        abort_unless(Auth::user()->isClientAdmin(), 403);

        $tenant = Auth::user()->tenant;

        if ($tenant->whatsapp_call_ringtone_path === null) {
            return;
        }

        Storage::disk(self::DISK)->delete($tenant->whatsapp_call_ringtone_path);

        $tenant->update([
            'whatsapp_call_ringtone_path' => null,
            'whatsapp_call_ringtone_original_name' => null,
            'whatsapp_call_ringtone_size' => null,
        ]);

        Flux::toast(variant: 'success', text: __('Ringtone removed — calls will use the default tone again.'));
    }
}
