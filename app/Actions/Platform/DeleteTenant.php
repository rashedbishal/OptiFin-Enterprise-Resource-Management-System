<?php

namespace App\Actions\Platform;

use App\Models\Lead;
use App\Models\LeadAttachment;
use App\Models\LeadImport;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Permanently, irreversibly erases a tenant and everything it owns —
 * mother-admin-only (see TenantPolicy::delete()), and only once the
 * tenant's Client Admin has already been deactivated via
 * ManageClientAdminActivation (see Platform\Clients\Show's own
 * precondition check before this is ever called).
 *
 * Every tenant-scoped table cascade-deletes at the database level when
 * the Tenant row goes (confirmed: every tenant_id foreign key in this
 * schema is cascadeOnDelete or nullOnDelete) — so $tenant->delete() alone
 * is enough for every DB row. What that cascade can NEVER touch is files
 * actually sitting on disk (lead attachments, outbound message media, CSV
 * imports, the tenant's own custom ringtone) — a cascade is a raw SQL
 * operation, it never fires Eloquent's deleting() event, which is the
 * only place that normally knows to delete those files (see
 * LeadObserver::deleting()'s own docblock, and
 * CleanupOrphanedLeadAttachments, which exists specifically to sweep up
 * exactly this kind of orphan later). For a "clean deletion" this reads
 * every such path BEFORE deleting anything, then deletes the files
 * itself immediately afterward, synchronously — not deferred to that
 * periodic sweep.
 */
class DeleteTenant
{
    public function handle(Tenant $tenant): void
    {
        $tenantId = $tenant->id;
        $tenantName = $tenant->name;
        $paths = $this->collectStoragePaths($tenant);

        DB::transaction(function () use ($tenant): void {
            $tenant->delete();
        });

        if ($paths->isNotEmpty()) {
            Storage::disk('local')->delete($paths->all());
        }

        Log::warning("Tenant [{$tenantName}] ({$tenantId}) was permanently deleted by a Platform mother admin.");
    }

    /**
     * @return Collection<int, string>
     */
    private function collectStoragePaths(Tenant $tenant): Collection
    {
        $leadAttachmentPaths = LeadAttachment::query()
            ->whereIn('lead_id', Lead::query()->where('tenant_id', $tenant->id)->select('id'))
            ->whereNotNull('path')
            ->pluck('path');

        $messageAttachmentPaths = Message::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('attachment_path')
            ->pluck('attachment_path');

        $leadImportFilePaths = LeadImport::query()
            ->where('tenant_id', $tenant->id)
            ->pluck('file_path');

        return $leadAttachmentPaths
            ->merge($messageAttachmentPaths)
            ->merge($leadImportFilePaths)
            ->push($tenant->whatsapp_call_ringtone_path)
            ->filter()
            ->unique()
            ->values();
    }
}
