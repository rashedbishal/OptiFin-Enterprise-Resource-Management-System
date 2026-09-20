<?php

namespace App\Actions\Tenant;

use App\Events\LeadUpdated;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadAttachment;
use App\Models\LeadConversationDeletion;
use App\Models\Message;
use App\Models\TenantUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Wipes a lead's conversation and nothing else — the lead, its status,
 * notes, tasks, deals, tags, score, consent and merge history all stay. A
 * conversation lives in four places, all cleared here:
 *   1. `conversations` + `messages` rows (including outbound image/PDF rows),
 *   2. `lead_attachments` rows (inbound files),
 *   3. `leads.source_data['messages']` — the raw webhook copy the feed also
 *      reads; ONLY that key is removed, since the rest (facebook_user_id,
 *      instagram_user_id, ...) is what matches future messages to this lead,
 *   4. `leads.message` — the accumulated inbound text.
 *
 * Rows, the audit record and the lead's own columns change in ONE
 * transaction; the files on disk are deleted only AFTER it commits, from
 * paths read beforehand, so a storage failure can never roll back or
 * resurrect the rows (leads:cleanup-attachments sweeps any leftover file).
 * Authorization, the password check and the merged-lead guard stay at the
 * call site, like RecordLeadDeletionAudit's callers.
 */
class DeleteLeadConversation
{
    /**
     * What a deletion would remove, for the confirmation modal.
     *
     * @return array{messages: int, conversations: int, attachments: int, files: int}
     */
    public function summarize(Lead $lead): array
    {
        return [
            'messages' => Message::where('lead_id', $lead->id)->count(),
            'conversations' => Conversation::where('lead_id', $lead->id)->count(),
            'attachments' => LeadAttachment::where('lead_id', $lead->id)->count(),
            'files' => count($this->storedPaths($lead)),
        ];
    }

    public function handle(Lead $lead, ?TenantUser $deletedBy): LeadConversationDeletion
    {
        $paths = [];

        $audit = DB::transaction(function () use ($lead, $deletedBy, &$paths): LeadConversationDeletion {
            $locked = Lead::where('tenant_id', $lead->tenant_id)->whereKey($lead->id)->lockForUpdate()->firstOrFail();

            $paths = $this->storedPaths($locked);
            $summary = $this->summarize($locked);

            $audit = LeadConversationDeletion::create([
                'tenant_id' => $locked->tenant_id,
                'lead_id' => $locked->id,
                'lead_identifier' => $locked->identifier,
                'lead_name' => $locked->name,
                'channel' => $locked->source->type->value,
                'deleted_by' => $deletedBy?->id,
                'summary' => $summary,
            ]);

            Message::where('lead_id', $locked->id)->delete();
            Conversation::where('lead_id', $locked->id)->delete();
            LeadAttachment::where('lead_id', $locked->id)->delete();

            $sourceData = $locked->source_data ?? [];
            unset($sourceData['messages']);

            $locked->forceFill(['source_data' => $sourceData, 'message' => null])->save();

            return $audit;
        });

        Storage::disk('local')->delete($paths);

        event(new LeadUpdated($lead));

        return $audit;
    }

    /**
     * Every file on disk that belongs to this lead's conversation: inbound
     * attachment files plus outbound image/PDF files (a failed message and
     * its resend share one path, hence the unique()).
     *
     * @return array<int, string>
     */
    private function storedPaths(Lead $lead): array
    {
        return LeadAttachment::where('lead_id', $lead->id)->whereNotNull('path')->pluck('path')
            ->merge(Message::where('lead_id', $lead->id)->whereNotNull('attachment_path')->pluck('attachment_path'))
            ->unique()
            ->values()
            ->all();
    }
}
