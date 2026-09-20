<?php

namespace App\Console\Commands;

use App\Models\LeadAttachment;
use App\Models\Message;
use App\Services\OutboundMediaStorage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes lead attachment files under `leads/` on the private `local` disk
 * that no longer belong to any lead — left behind when a lead source or
 * tenant is deleted (both cascade-delete leads, and therefore their
 * attachments, at the database level, bypassing the `LeadObserver::deleting()`
 * hook that cleans up on a normal per-lead delete) or by a download that
 * crashed before its DB write. Also sweeps `outbound/` (outbound image/PDF
 * replies — see OutboundMediaStorage) against messages.attachment_path, the
 * same way, with the same grace period.
 */
#[Signature('leads:cleanup-attachments')]
#[Description('Delete orphaned lead attachment files that no longer belong to any lead')]
class CleanupOrphanedLeadAttachments extends Command
{
    /**
     * Orphaned files newer than this are skipped — they may belong to a
     * download still in progress (file written, `Lead::update()` not yet
     * reached), which isn't an orphan yet.
     */
    private const GRACE_PERIOD_MINUTES = 60;

    public function handle(): int
    {
        $disk = Storage::disk('local');

        $deleted = $this->sweep('leads', array_flip(LeadAttachment::query()->whereNotNull('path')->pluck('path')->all()))
            + $this->sweep(OutboundMediaStorage::DIRECTORY, array_flip(Message::query()->whereNotNull('attachment_path')->pluck('attachment_path')->all()));

        $this->info($deleted === 0 && ! $disk->exists('leads') && ! $disk->exists(OutboundMediaStorage::DIRECTORY)
            ? 'Nothing to clean up.'
            : "Deleted {$deleted} orphaned attachment file(s).");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $knownPaths  flipped set of paths still referenced by a row
     */
    private function sweep(string $directory, array $knownPaths): int
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($directory)) {
            return 0;
        }

        $cutoff = now()->subMinutes(self::GRACE_PERIOD_MINUTES)->timestamp;
        $deleted = 0;

        foreach ($disk->allFiles($directory) as $path) {
            if (isset($knownPaths[$path])) {
                continue;
            }

            if ($disk->lastModified($path) > $cutoff) {
                continue;
            }

            $disk->delete($path);
            $deleted++;
        }

        return $deleted;
    }
}
