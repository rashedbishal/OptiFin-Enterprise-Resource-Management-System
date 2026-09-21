<?php

namespace App\Console\Commands;

use App\Actions\Tenant\FinalizeStaleLeadCalls;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp inbound calling (Step 6) — the every-minute sweep that ends calls
 * whose terminate webhook never arrived. A single global run: the candidate
 * queries ride the (status, ringing_expires_at) index, every transition is a
 * conditional UPDATE (see FinalizeStaleLeadCalls), and the schedule's
 * withoutOverlapping() is only a courtesy — a second concurrent run is safe.
 * Exits non-zero when any row failed so ScheduledTaskMonitor alerts.
 */
#[Signature('calls:reconcile-stale')]
#[Description('End WhatsApp calls whose terminate webhook never arrived (stale Ringing, Answering and Connected calls)')]
class ReconcileStaleLeadCalls extends Command
{
    public function handle(FinalizeStaleLeadCalls $finalize): int
    {
        $startedAt = hrtime(true);

        $summary = $finalize->handle();
        $summary['duration_ms'] = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        Log::channel('whatsapp')->info('WA CALL RECONCILE RUN', $summary);

        $this->info(sprintf(
            'Inspected %d call(s): %d ring timeout, %d answer timeout, %d stale connected, %d lost race, %d failure(s).',
            $summary['inspected'],
            $summary['missed_ring'],
            $summary['missed_answering'],
            $summary['completed_connected'],
            $summary['lost_race'],
            $summary['failures'],
        ));

        return $summary['failures'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
