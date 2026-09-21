<?php

namespace App\Actions\Tenant;

use App\Enums\CallStatus;
use App\Models\LeadCall;
use App\Models\TenantUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * WhatsApp inbound calling — the softphone's recovery read: "which calls are
 * live for ME right now?". Realtime events are only a nudge to call this; it is
 * the single source of truth the browser reconciles against after a reload, a
 * reconnect, a lost event or a wake from sleep.
 *
 * Read-only: it never expires or otherwise changes a call (reconciling stale
 * rows is a later step's job). Rows are:
 *  - Ringing, still inside their ring window, with this user in the frozen
 *    routing snapshot; and
 *  - Answering or Connected, claimed by this user, within
 *    `calling.active_window_minutes` of when they were claimed / connected — so
 *    a row whose terminate webhook never arrived cannot be listed forever.
 * Always inside the user's own tenant, newest first, at most 20.
 *
 * Deliberately minimal output: no SDP, no Meta stage, no routing internals, no
 * credentials — the claim endpoint remains the only thing that hands out an offer.
 */
class ListActiveLeadCalls
{
    public const int LIMIT = 20;

    /**
     * @return array<int, array{id: string, status: string, claimed_by_me: bool, lead: array{id: string, name: ?string, phone: ?string}, expires_in_seconds: ?int, connected_at: ?string}>
     */
    public function handle(TenantUser $user): array
    {
        $now = Carbon::now();
        $cutoff = $now->copy()->subMinutes((int) config('calling.active_window_minutes'));

        $calls = LeadCall::query()
            ->where('tenant_id', $user->tenant_id)
            ->where(function ($query) use ($user, $now, $cutoff): void {
                $query
                    ->where(fn ($ringing) => $ringing
                        ->where('status', CallStatus::Ringing->value)
                        ->where('ringing_expires_at', '>', $now)
                        ->whereJsonContains('routed_user_ids', $user->id))
                    ->orWhere(fn ($answering) => $answering
                        ->where('status', CallStatus::Answering->value)
                        ->where('claimed_by', $user->id)
                        ->where('claimed_at', '>=', $cutoff))
                    ->orWhere(fn ($connected) => $connected
                        ->where('status', CallStatus::Connected->value)
                        ->where('claimed_by', $user->id)
                        ->where('connected_at', '>=', $cutoff));
            })
            ->with('lead:id,name,phone')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        // Every row already satisfies the routed / claimed filter above, so the
        // policy (tenant, active user, lead-management update) is evaluated once
        // rather than once per row — it does not vary between them.
        if ($calls->isEmpty() || Gate::forUser($user)->denies('answer', $calls->first())) {
            return [];
        }

        return $calls->map(fn (LeadCall $call): array => [
            'id' => $call->id,
            'status' => $call->status->value,
            'claimed_by_me' => $call->claimed_by === $user->id,
            'lead' => [
                'id' => $call->lead_id,
                'name' => $call->lead->name,
                'phone' => $call->lead->phone,
            ],
            'expires_in_seconds' => $this->expiresIn($call, $now),
            'connected_at' => $call->connected_at?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * Seconds left of the answer window, computed on the server so the browser's
     * own clock never decides whether a call can still be answered. Null once
     * the call is Connected (the window no longer applies).
     */
    private function expiresIn(LeadCall $call, Carbon $now): ?int
    {
        if ($call->status === CallStatus::Connected || $call->ringing_expires_at === null) {
            return null;
        }

        return max(0, (int) $now->diffInSeconds($call->ringing_expires_at, false));
    }
}
