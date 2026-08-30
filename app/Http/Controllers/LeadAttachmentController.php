<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadAttachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadAttachmentController extends Controller
{
    /**
     * Stream one of a lead's attachments to an authorized tenant user.
     * Deliberately not served from the public disk: a guessable public URL
     * would let anyone fetch a lead's photo/document/voice note across
     * tenants, so this route re-checks the same policy as viewing the lead
     * itself, and confirms the attachment actually belongs to that lead.
     */
    public function download(Lead $lead, LeadAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $lead);

        abort_unless($attachment->lead_id === $lead->id, 404);
        abort_unless(
            $attachment->path !== null && Storage::disk('local')->exists($attachment->path),
            404
        );

        return Storage::disk('local')->download($attachment->path, null, array_filter([
            'Content-Type' => $attachment->mime,
        ]));
    }
}
