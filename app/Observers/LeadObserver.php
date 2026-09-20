<?php

namespace App\Observers;

use App\Models\Lead;
use App\Services\LeadDuplicateDetectionService;
use Illuminate\Support\Facades\Storage;

class LeadObserver
{
    /**
     * A lead's attachment files live on the private `local` disk, reachable
     * only through each `LeadAttachment` row's `path` column. Those rows
     * cascade-delete at the database level when the lead does, which means
     * by the time a `deleted` hook would fire they're already gone and
     * unreachable — so this reads the paths beforehand, on `deleting`.
     */
    public function deleting(Lead $lead): void
    {
        $paths = $lead->attachments()->whereNotNull('path')->pluck('path');

        // Same reasoning for outbound image/PDF replies: the Message rows
        // cascade away with the lead, so their files are read first. A
        // merged-away lead's messages were already reparented onto the
        // survivor, so nothing the survivor still needs is ever touched.
        $outboundPaths = $lead->messages()->whereNotNull('attachment_path')->pluck('attachment_path');

        Storage::disk('local')->delete($paths->merge($outboundPaths)->all());
    }

    /**
     * Keeps email_normalized/phone_normalized (see
     * App\Services\LeadDuplicateDetectionService::detectFor()) in sync with
     * email/phone on every create and update, using the exact same
     * normalizeEmail()/normalizePhone() static methods the detection
     * service itself uses — a single source of truth for normalization,
     * not a second implementation. Only recomputes when the source column
     * is actually dirty, so this is a no-op on every save that doesn't
     * touch email/phone (the common case).
     */
    public function saving(Lead $lead): void
    {
        if ($lead->isDirty('email')) {
            $lead->email_normalized = LeadDuplicateDetectionService::normalizeEmail($lead->email);
        }

        if ($lead->isDirty('phone')) {
            $lead->phone_normalized = LeadDuplicateDetectionService::normalizePhone($lead->phone);
        }
    }
}
