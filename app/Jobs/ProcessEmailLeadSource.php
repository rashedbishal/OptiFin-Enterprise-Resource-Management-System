<?php

namespace App\Jobs;

use App\Enums\LeadStatus;
use App\Events\LeadReceived;
use App\Models\Lead;
use App\Models\LeadSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use IMAP\Connection;

class ProcessEmailLeadSource implements ShouldQueue
{
    use Queueable;

    public function __construct(public LeadSource $source) {}

    public function handle(): void
    {
        $config = $this->source->config ?? [];

        $mailbox = sprintf('{%s:%d/imap/ssl}INBOX', $config['imap_host'] ?? '', $config['imap_port'] ?? 993);

        $inbox = @imap_open($mailbox, $config['imap_username'] ?? '', $config['imap_password'] ?? '');

        if (! $inbox instanceof Connection) {
            Log::error("Unable to connect to lead source [{$this->source->id}]: ".imap_last_error());

            return;
        }

        $messageNumbers = imap_search($inbox, 'UNSEEN') ?: [];

        foreach ($messageNumbers as $messageNumber) {
            $this->createLeadFromEmail($inbox, (int) $messageNumber);
            imap_setflag_full($inbox, (string) $messageNumber, '\\Seen');
        }

        imap_close($inbox);

        $this->source->update(['last_synced_at' => now()]);
    }

    private function createLeadFromEmail(Connection $inbox, int $messageNumber): void
    {
        $header = imap_headerinfo($inbox, $messageNumber);
        $body = imap_fetchbody($inbox, $messageNumber, '1') ?: imap_fetchbody($inbox, $messageNumber, '');

        $from = $header->from[0] ?? null;
        $email = $from !== null ? "{$from->mailbox}@{$from->host}" : null;
        $name = $from->personal ?? $email ?? 'Unknown Sender';

        $lead = Lead::create([
            'tenant_id' => $this->source->tenant_id,
            'source_id' => $this->source->id,
            'name' => $name,
            'email' => $email,
            'message' => trim((string) $body),
            'source_data' => ['subject' => $header->subject ?? null],
            'status' => LeadStatus::New,
        ]);

        event(new LeadReceived($lead));
    }
}
