<?php

namespace Tests\Support;

use App\Contracts\ReverbChannelUsersGateway;
use RuntimeException;

/**
 * WhatsApp Call Backup / Overflow Receiver, Phase 2 — the fake bound in place
 * of App\Services\PusherReverbChannelUsersGateway for every test. Tests MUST
 * NOT contact a real Reverb server; this is the seam that guarantees that —
 * see App\Contracts\ReverbChannelUsersGateway's own docblock.
 *
 * Records the exact channel name(s) it was asked about so a test can assert
 * ReverbPresenceQuery built the right channel name, without ever needing a
 * real Reverb server to answer it.
 */
class FakeReverbChannelUsersGateway implements ReverbChannelUsersGateway
{
    /** @var array<int, string>|null */
    private ?array $members = null;

    /** @var array<int, string> */
    public array $queriedChannels = [];

    /**
     * @param  array<int, string>  $ids
     */
    public function returningMembers(array $ids): static
    {
        $this->members = $ids;

        return $this;
    }

    public function failing(): static
    {
        $this->members = null;

        return $this;
    }

    public function memberIds(string $channelName): array
    {
        $this->queriedChannels[] = $channelName;

        if ($this->members === null) {
            throw new RuntimeException('FakeReverbChannelUsersGateway: simulated Reverb query failure.');
        }

        return $this->members;
    }
}
