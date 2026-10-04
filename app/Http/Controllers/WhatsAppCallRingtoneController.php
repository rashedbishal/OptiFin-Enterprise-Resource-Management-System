<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WhatsAppCallRingtoneController extends Controller
{
    /**
     * Streams the authenticated user's own tenant's custom WhatsApp call
     * ringtone inline (never a forced download — the softphone plays it
     * straight from an <audio> element). Not served from the public disk:
     * see LeadAttachmentController::download()'s identical reasoning — a
     * guessable public URL would let anyone across tenants fetch another
     * tenant's uploaded file.
     */
    public function show(): StreamedResponse
    {
        $tenant = Auth::user()->tenant;
        $path = $tenant->whatsapp_call_ringtone_path;

        abort_unless($path !== null && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => 'audio/mpeg',
        ]);
    }
}
