<?php

namespace App\Livewire\Tenant;

use App\Notifications\FollowUpAlertNotification;
use App\Notifications\FollowUpCancelledNotification;
use App\Notifications\FollowUpUpdatedNotification;
use App\Notifications\LeadAssignedNotification;
use App\Notifications\MeetingAlertNotification;
use App\Notifications\MeetingCancelledNotification;
use App\Notifications\MeetingUpdatedNotification;
use App\Notifications\NewLeadNotification;
use App\Services\NotificationUrlResolver;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Global, always-mounted bell — embedded once in layouts/app.blade.php (see
 * that file for why) rather than per-page, so it stays independent of
 * whichever page component is currently active. Surfaces every
 * "database"-channel notification a tenant user can receive: NewLead
 * (Client Admins only, see SendNewLeadNotification), LeadAssigned (the
 * assignee), MeetingAlert/FollowUpAlert (see
 * App\Console\Commands\SendLeadAlertNotifications), and
 * MeetingCancelled/FollowUpCancelled/MeetingUpdated/FollowUpUpdated (every
 * *other* active Client Admin, never whoever performed the action — see
 * App\Services\LeadAdminNotifier). Visibility is always entirely a
 * function of who the sender chose to notify, not anything checked here,
 * so a Client User only ever sees ones for leads assigned to them purely
 * because they're the only ones stored against their own notifiable.
 *
 * Updates in real time via Reverb: App\Events\UserNotified broadcasts to
 * this user's own private channel the moment any notification lands for
 * them (see App\Listeners\BroadcastUserNotification), and refresh() below
 * just re-queries — no polling, no reconstructing a notification from a
 * broadcast payload that could drift out of sync with present() below.
 */
class NotificationBell extends Component
{
    private const int RECENT_LIMIT = 20;

    /**
     * Whether notifications() has been fetched at least once this page
     * load — gates that query so it stays lazy (never runs on a plain page
     * load where the dropdown is never opened, same as before this
     * property existed) while letting openNotifications() show a skeleton
     * for the brief round trip a click triggers. Deliberately does NOT
     * gate unreadCount() (the bell icon's badge, shown regardless of
     * whether the dropdown has ever been opened) — that must stay
     * immediately accurate on every page load.
     *
     * The dropdown's open/closed VISIBILITY itself is no longer tracked
     * here at all — it moved to a plain Alpine-local boolean in
     * notification-bell.blade.php (`x-data="{ open: false }"`) so
     * clicking the bell shows the dropdown shell (and its skeleton)
     * instantly, without waiting on a round trip first. openNotifications()
     * below is fired in parallel with that instant client-side toggle,
     * purely to fetch/refresh the data.
     */
    public bool $notificationsLoaded = false;

    public bool $showClearAllModal = false;

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            'echo-private:tenant-user.'.Auth::id().',.notification.created' => 'refresh',
        ];
    }

    public function refresh(): void
    {
        unset($this->unreadCount, $this->notifications);
    }

    /**
     * Fired by the bell button's click alongside its instant Alpine-local
     * open toggle (see $notificationsLoaded's docblock) — always
     * re-fetches fresh, same as every click did before this method
     * existed (the old $open-toggling toggle() re-triggered notifications()
     * on every open, not just the first).
     */
    public function openNotifications(): void
    {
        $this->notificationsLoaded = true;
        unset($this->unreadCount, $this->notifications);
    }

    #[Computed]
    public function unreadCount(): int
    {
        return Auth::user()->unreadNotifications()->count();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function notifications(): array
    {
        if (! $this->notificationsLoaded) {
            return [];
        }

        return Auth::user()->notifications()
            ->latest()
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => $this->present($notification))
            ->all();
    }

    /**
     * Marks one notification read without navigating anywhere — the small
     * unread-dot toggle on each row. Clicking the row itself instead goes
     * through NotificationRedirectController, which marks it read and
     * redirects server-side in one round trip (see present()'s 'url').
     */
    public function markAsRead(string $id): void
    {
        Auth::user()->notifications()->whereKey($id)->first()?->markAsRead();

        unset($this->unreadCount, $this->notifications);
    }

    public function markAllAsRead(): void
    {
        Auth::user()->unreadNotifications->markAsRead();

        unset($this->unreadCount, $this->notifications);
    }

    public function confirmClearAll(): void
    {
        $this->showClearAllModal = true;
    }

    public function clearAllConfirmed(): void
    {
        $this->clearAll();
        $this->showClearAllModal = false;
    }

    public function clearAll(): void
    {
        Auth::user()->notifications()->delete();

        unset($this->unreadCount, $this->notifications);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DatabaseNotification $notification): array
    {
        $data = $notification->data;

        [$icon, $title, $message] = match ($notification->type) {
            NewLeadNotification::class => [
                'user-plus',
                __('New lead received'),
                __(':name via :source', [
                    'name' => $data['name'] ?? __('Unknown'),
                    'source' => $data['source_name'] ?? '',
                ]),
            ],
            LeadAssignedNotification::class => [
                'inbox-arrow-down',
                ($data['assigned_by'] ?? null) !== null
                    ? __(':assignedBy assigned you a lead', ['assignedBy' => $data['assigned_by']])
                    : __('A lead was assigned to you'),
                $data['name'] ?? '',
            ],
            MeetingAlertNotification::class => [
                'calendar-days',
                __('Meeting reminder'),
                $this->dateReminderMessage($data, 'meeting_date'),
            ],
            FollowUpAlertNotification::class => [
                'clock',
                __('Follow-up reminder'),
                $this->dateReminderMessage($data, 'next_follow_up_at'),
            ],
            MeetingCancelledNotification::class => [
                'x-circle',
                __('Meeting cancelled'),
                __(':cancelledBy cancelled the meeting for :name', [
                    'cancelledBy' => $data['cancelled_by'] ?? __('Someone'),
                    'name' => $data['name'] ?? '',
                ]),
            ],
            FollowUpCancelledNotification::class => [
                'x-circle',
                __('Follow-up cancelled'),
                __(':cancelledBy cancelled the follow-up for :name', [
                    'cancelledBy' => $data['cancelled_by'] ?? __('Someone'),
                    'name' => $data['name'] ?? '',
                ]),
            ],
            MeetingUpdatedNotification::class => [
                'calendar-days',
                ($data['rescheduled'] ?? false) ? __('Meeting rescheduled') : __('Meeting scheduled'),
                $this->dateChangeMessage($data, 'meeting_date', 'meeting_time'),
            ],
            FollowUpUpdatedNotification::class => [
                'clock',
                ($data['rescheduled'] ?? false) ? __('Follow-up rescheduled') : __('Follow-up scheduled'),
                $this->dateChangeMessage($data, 'next_follow_up_at'),
            ],
            default => ['bell', __('Notification'), ''],
        };

        $targetUrl = NotificationUrlResolver::resolve(Auth::user(), $data);

        return [
            'id' => $notification->id,
            'icon' => $icon,
            'title' => $title,
            'message' => $message,
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at,
            // Points at NotificationRedirectController rather than
            // $targetUrl directly: marking read and navigating used to be
            // two independent requests (wire:click.renderless alongside
            // wire:navigate on the same anchor), which raced — wire:navigate
            // can swap the page, tearing down this component, before the
            // separate markAsRead() commit is guaranteed to have reached the
            // server, leaving the notification stuck unread after the user
            // comes back. Routing through one controller makes "mark read"
            // and "redirect to the lead" a single atomic round trip.
            'url' => $targetUrl !== null ? route('tenant-notifications.open', $notification->id) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function dateReminderMessage(array $data, string $dateKey): string
    {
        $name = $data['name'] ?? __('This lead');
        $date = $data[$dateKey] ?? null;

        if (! is_string($date) || $date === '') {
            return $name;
        }

        return __(':name — :date', ['name' => $name, 'date' => Carbon::parse($date)->format('M j, Y')]);
    }

    /**
     * $timeKey only ever applies to meeting_date — next_follow_up_at has no
     * companion time column at all (see Lead::meeting_time's own docblock),
     * so FollowUpUpdatedNotification simply never passes one.
     *
     * @param  array<string, mixed>  $data
     */
    private function dateChangeMessage(array $data, string $dateKey, ?string $timeKey = null): string
    {
        $changedBy = $data['changed_by'] ?? __('Someone');
        $name = $data['name'] ?? __('a lead');
        $date = $data[$dateKey] ?? null;

        if (! is_string($date) || $date === '') {
            return __(':changedBy updated :name', ['changedBy' => $changedBy, 'name' => $name]);
        }

        $time = $timeKey !== null ? ($data[$timeKey] ?? null) : null;

        if (is_string($time) && $time !== '') {
            return __(':changedBy set :name to :date at :time', [
                'changedBy' => $changedBy,
                'name' => $name,
                'date' => Carbon::parse($date)->format('M j, Y'),
                'time' => $time,
            ]);
        }

        return __(':changedBy set :name to :date', [
            'changedBy' => $changedBy,
            'name' => $name,
            'date' => Carbon::parse($date)->format('M j, Y'),
        ]);
    }
}
