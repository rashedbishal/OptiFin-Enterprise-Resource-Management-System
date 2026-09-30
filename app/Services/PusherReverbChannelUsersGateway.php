<?php

namespace App\Services;

use App\Contracts\ReverbChannelUsersGateway;
use GuzzleHttp\Client as GuzzleClient;
use InvalidArgumentException;
use Pusher\Pusher;

/**
 * WhatsApp Call Backup / Overflow Receiver, Phase 2 — the real implementation
 * of ReverbChannelUsersGateway, built directly on the already-installed
 * pusher-php-server client (never hand-rolled request signing) and the
 * app's own already-configured Reverb connection
 * (config('broadcasting.connections.reverb')) — same key/secret/app_id/host/
 * port every broadcast already uses, never a second set of credentials.
 *
 * Deliberately builds its OWN Guzzle client with its OWN short timeout
 * (calling.reverb_presence_timeout_seconds /
 * ...connect_timeout_seconds) rather than reusing whatever client the
 * broadcaster singleton happens to be using — this call sits on the inbound
 * webhook's hot path and must never inherit a longer timeout tuned for
 * fire-and-forget event broadcasting.
 *
 * Throws on any failure (bad credentials, network error, timeout, non-200,
 * malformed body) rather than returning an empty list — an empty list would
 * be indistinguishable from "channel genuinely has nobody on it," which
 * ReverbPresenceQuery must be able to tell apart from "the query itself
 * failed" (see its own docblock).
 */
class PusherReverbChannelUsersGateway implements ReverbChannelUsersGateway
{
    public function memberIds(string $channelName): array
    {
        $config = config('broadcasting.connections.reverb');

        if (! is_array($config) || ! is_string($config['key'] ?? null) || ! is_string($config['secret'] ?? null) || ! is_string($config['app_id'] ?? null)) {
            throw new InvalidArgumentException('The "reverb" broadcasting connection is not configured.');
        }

        $pusher = new Pusher(
            $config['key'],
            $config['secret'],
            $config['app_id'],
            is_array($config['options'] ?? null) ? $config['options'] : [],
            new GuzzleClient([
                'timeout' => (float) config('calling.reverb_presence_timeout_seconds'),
                'connect_timeout' => (float) config('calling.reverb_presence_connect_timeout_seconds'),
            ]),
        );

        /** @var array{users?: array<int, array{id?: mixed}>} $body */
        $body = $pusher->get('/channels/'.$channelName.'/users', [], true);

        return collect($body['users'] ?? [])
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id) || is_int($id))
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }
}
