<?php

namespace App\Contracts;

/**
 * WhatsApp Call Backup / Overflow Receiver, Phase 2 — the one seam between
 * App\Services\ReverbPresenceQuery and an actual Reverb server, so tests can
 * substitute a fake and never make a real network call. The real
 * implementation (App\Services\PusherReverbChannelUsersGateway) wraps
 * Reverb's Pusher-compatible "channel users" HTTP endpoint.
 */
interface ReverbChannelUsersGateway
{
    /**
     * The ids of every member currently present on the given presence
     * channel (already deduplicated by Reverb itself — see
     * ReverbPresenceQuery's own docblock on multi-tab/multi-device).
     *
     * @return array<int, string>
     *
     * @throws \Throwable on any failure to reach or parse Reverb's response —
     *                    callers must treat this as "unknown", never "empty".
     */
    public function memberIds(string $channelName): array;
}
