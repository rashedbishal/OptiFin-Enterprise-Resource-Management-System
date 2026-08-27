<?php

namespace App\Console\Commands;

use App\Enums\LeadSourceType;
use App\Jobs\ProcessEmailLeadSource;
use App\Models\LeadSource;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leads:fetch-emails')]
#[Description('Fetch unread emails from active email lead sources and create leads from them')]
class FetchEmailLeads extends Command
{
    public function handle(): int
    {
        if (! extension_loaded('imap')) {
            $this->error('The IMAP extension is not available on this server.');

            return self::FAILURE;
        }

        LeadSource::query()
            ->where('type', LeadSourceType::Email)
            ->where('is_active', true)
            ->get()
            ->filter($this->isDue(...))
            ->each(function (LeadSource $source): void {
                ProcessEmailLeadSource::dispatch($source);
            });

        return self::SUCCESS;
    }

    /**
     * Determine whether a lead source is due for a fetch based on its
     * configured fetch interval and when it was last synced.
     */
    private function isDue(LeadSource $source): bool
    {
        if ($source->last_synced_at === null) {
            return true;
        }

        $intervalMinutes = (int) ($source->config['fetch_interval_minutes'] ?? 15);

        return $source->last_synced_at->addMinutes($intervalMinutes)->isPast();
    }
}
