<?php

namespace App\Http\Controllers;

use App\Services\NotificationUrlResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The click target for every clickable row in NotificationBell — marks the
 * notification read and redirects to its lead in one request, rather than
 * NotificationBell firing a separate wire:click.renderless "mark as read"
 * commit alongside the anchor's own wire:navigate. Those raced: wire:navigate
 * can swap the page (tearing this Livewire component down) before the
 * independent markAsRead() request is guaranteed to have reached the server,
 * so the notification could still show unread after the user came back.
 * Doing both in one server round trip makes that race impossible.
 */
class NotificationRedirectController extends Controller
{
    public function __invoke(string $notification): RedirectResponse
    {
        $user = Auth::user();

        $record = $user->notifications()->whereKey($notification)->firstOrFail();

        $record->markAsRead();

        $url = NotificationUrlResolver::resolve($user, $record->data);

        return redirect()->to($url ?? route('dashboard'));
    }
}
