<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Enums\TenantUserType;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\FollowUpAlertNotification;
use App\Notifications\MeetingAlertNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * The bell-notification counterpart to the Kanban card shimmer
 * (Lead::meetingAlertActive()/followUpAlertActive()): that shimmer is a
 * live, always-recomputed state with nothing persisted behind it, so
 * turning it into a notification-center entry needs this daily sweep to
 * create one. Runs once a day (see routes/console.php) rather than
 * per-minute like leads:fetch-emails — a meeting/follow-up reminder losing
 * a few hours of precision to the schedule doesn't warrant that cost, and
 * the once-a-day cadence is also what keeps this idempotent (see
 * alreadyNotifiedToday()) without needing its own state table.
 */
#[Signature('leads:send-alert-notifications')]
#[Description('Send bell notifications for leads whose meeting/follow-up alert is currently active')]
class SendLeadAlertNotifications extends Command
{
    public function handle(): int
    {
        Tenant::query()
            ->where('status', TenantStatus::Active)
            ->where(function ($query): void {
                $query->where('meeting_alert_enabled', true)
                    ->orWhere('followup_alert_enabled', true);
            })
            ->each(function (Tenant $tenant): void {
                if ($tenant->meeting_alert_enabled) {
                    $this->sweep($tenant, 'meeting_date', fn (Lead $lead) => $lead->meetingAlertActive(true, $tenant->meeting_alert_days_before), MeetingAlertNotification::class, fn (Lead $lead) => new MeetingAlertNotification($lead));
                }

                if ($tenant->followup_alert_enabled) {
                    $this->sweep($tenant, 'next_follow_up_at', fn (Lead $lead) => $lead->followUpAlertActive(true, $tenant->followup_alert_days_before), FollowUpAlertNotification::class, fn (Lead $lead) => new FollowUpAlertNotification($lead));
                }
            });

        return self::SUCCESS;
    }

    /**
     * @param  callable(Lead): bool  $isActive
     * @param  callable(Lead): (MeetingAlertNotification|FollowUpAlertNotification)  $makeNotification
     */
    private function sweep(Tenant $tenant, string $dateColumn, callable $isActive, string $notificationClass, callable $makeNotification): void
    {
        $admins = $tenant->tenantUsers()
            ->where('user_type', TenantUserType::ClientAdmin)
            ->where('is_active', true)
            ->get();

        $tenant->leads()
            ->whereNotNull($dateColumn)
            ->with('assignedUser')
            ->each(function (Lead $lead) use ($isActive, $notificationClass, $makeNotification, $admins): void {
                if (! $isActive($lead)) {
                    return;
                }

                foreach ($this->recipients($admins, $lead) as $recipient) {
                    if ($this->alreadyNotifiedToday($recipient, $notificationClass, $lead)) {
                        continue;
                    }

                    $recipient->notify($makeNotification($lead));
                }
            });
    }

    /**
     * Every active Client Admin (they see alerts for every lead in the
     * tenant) plus the lead's own assignee if it has one and isn't already
     * in that set — a Client User only ever sees alerts for leads assigned
     * to them, matching every other lead visibility rule in this module
     * (see LeadPolicy::view).
     *
     * @param  Collection<int, TenantUser>  $admins
     * @return SupportCollection<int, TenantUser>
     */
    private function recipients(Collection $admins, Lead $lead): SupportCollection
    {
        $recipients = $admins->toBase();

        $assignee = $lead->assignedUser;

        if ($assignee instanceof TenantUser && $assignee->is_active && ! $recipients->contains('id', $assignee->id)) {
            $recipients->push($assignee);
        }

        return $recipients;
    }

    /**
     * Guards against a duplicate for the same lead/recipient/day — the
     * schedule only fires once daily, but this keeps a manual re-run (or a
     * missed/retried scheduler tick) from spamming a second copy of the
     * same day's reminder.
     */
    private function alreadyNotifiedToday(TenantUser $recipient, string $notificationClass, Lead $lead): bool
    {
        // A >= today / < tomorrow range rather than whereDate('created_at',
        // ...): wrapping an indexed timestamp column in DATE() prevents the
        // database from using a plain range index on it (see the identical
        // fix in FiltersLeadsQuery). This runs once per lead per recipient,
        // every day, so it's worth keeping index-friendly.
        return $recipient->notifications()
            ->where('type', $notificationClass)
            ->where('data->lead_id', $lead->id)
            ->where('created_at', '>=', today())
            ->where('created_at', '<', today()->addDay())
            ->exists();
    }
}
