<?php

namespace App\Http\Controllers;

use App\Models\TenantUser;
use App\Services\CallingAppRelease;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings → Calling app → "Download app": streams the current APK from the
 * private disk to an eligible tenant user (their tenant has a WhatsApp lead
 * source with calling on; module access is the route's own middleware).
 */
class CallingAppDownloadController extends Controller
{
    public function __invoke(Request $request, CallingAppRelease $releases): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user instanceof TenantUser && CallingAppRelease::tenantMayDownload($user), 403);

        $release = $releases->current();

        abort_if($release === null, 404);

        return Storage::disk('local')->download(
            $release['path'],
            'OptiFin-Calling-'.$release['version'].'.apk',
            [
                'Content-Type' => 'application/vnd.android.package-archive',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }
}
