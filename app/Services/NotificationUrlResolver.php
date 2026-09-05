<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\TenantUser;

/**
 * Resolves a stored notification's data payload to the lead URL it should
 * open — shared between NotificationBell::present() (building the href
 * shown in the panel) and NotificationRedirectController (re-deriving the
 * same URL server-side at click time, after re-checking the lead still
 * exists). Keeping one implementation means the two can never drift apart.
 */
class NotificationUrlResolver
{
    /**
     * Null if the lead no longer exists (deleted since the notification was
     * created). Doesn't re-check authorization: every notification type is
     * only ever sent to someone already entitled to view that lead (see
     * NotificationBell's class docblock), so there's nothing left to gate
     * beyond existence.
     *
     * @param  array<string, mixed>  $data
     */
    public static function resolve(TenantUser $user, array $data): ?string
    {
        $leadId = $data['lead_id'] ?? null;

        if (! is_string($leadId) || ! Lead::whereKey($leadId)->exists()) {
            return null;
        }

        // A Client Admin's "view this lead" surface is the standalone
        // tenant-leads.show page (matching the row actions on their own
        // table view) — but a Client User only ever sees leads through the
        // Kanban board's flyout, so sending them to that same standalone
        // page would show them a screen laid out for a workflow they don't
        // otherwise use. Index::$openLeadId (a #[Url]-bound property) opens
        // that flyout directly from the query string on load instead.
        if ($user->isClientAdmin()) {
            return route('tenant-leads.show', $leadId);
        }

        return route('tenant-leads.index', ['lead' => $leadId]);
    }
}
