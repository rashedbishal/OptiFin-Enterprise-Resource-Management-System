<?php

namespace App\Http\Controllers;

use App\Enums\MessageDirection;
use App\Models\Lead;
use App\Models\Message;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadMessageAttachmentController extends Controller
{
    /**
     * Streams an outbound image/PDF reply to an authorized tenant user —
     * inline, so the chat feed can show the image and open the PDF. Same
     * private-disk / policy-recheck approach as LeadAttachmentController:
     * never a guessable public URL, and the message must actually belong to
     * the lead in the URL. The served type is the one sniffed at upload
     * time from an allow-list (jpeg/png/gif/pdf), with nosniff on top.
     */
    public function show(Lead $lead, Message $message): StreamedResponse
    {
        Gate::authorize('view', $lead);

        abort_unless($message->lead_id === $lead->id, 404);
        abort_unless($message->direction === MessageDirection::Outbound && $message->hasMedia(), 404);
        abort_unless(Storage::disk('local')->exists((string) $message->attachment_path), 404);

        return Storage::disk('local')->response((string) $message->attachment_path, $message->attachment_name, [
            'Content-Type' => $message->attachment_mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
