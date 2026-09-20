<?php

namespace App\Contracts;

use App\Models\Conversation;
use App\Models\Message;
use App\Support\SendResult;

/**
 * Feature #15 — a light contract, not a container-tag/service-discovery
 * abstraction (no existing pattern in this codebase uses one for a "one of
 * N known channel types" problem — every precedent, e.g.
 * LeadSourceType::configFields(), is a single enum with a match() per case;
 * see MessageSenderFactory). This interface exists so tests can substitute
 * a fake sender without an HTTP mock, the same reason ValidateWebsiteFormSubmission
 * etc. are already structured as plain, mockable classes.
 *
 * Implementations MUST resolve credentials from $conversation->leadSource->config
 * at call time, never from a cached/injected value — see each Sender's own
 * docblock.
 */
interface MessageSenderInterface
{
    public function send(Conversation $conversation, string $body): SendResult;

    /**
     * Sends one already-stored image/PDF Message (see
     * OutboundMediaStorage) — same "credentials read fresh, confirmed
     * platform rejection is a SendResult::failure(), a network exception
     * is left uncaught for the job's retry handling" contract as send().
     * Any caption travels in the same platform message only where the
     * channel supports it (MessageChannel::supportsMediaCaption()) —
     * otherwise the Message's body is null and the text was sent as its
     * own, separate Message.
     */
    public function sendMedia(Conversation $conversation, Message $message): SendResult;
}
