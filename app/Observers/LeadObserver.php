<?php

namespace App\Observers;

use App\Models\Lead;
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
        $paths = $lead->attachments()->pluck('path');

        Storage::disk('local')->delete($paths->all());
    }
}
