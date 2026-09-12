<?php

namespace App\Services;

use App\Contracts\MessageSenderInterface;
use App\Enums\MessageChannel;

/**
 * Feature #15 — resolves the right *Sender for a channel via a plain
 * match(), the same "one enum, one match() per case" shape this codebase
 * already uses everywhere for a "one of N known channel types" problem
 * (e.g. LeadSourceType::configFields()) — deliberately not a
 * container-tag/service-discovery abstraction, which has no precedent here.
 */
class MessageSenderFactory
{
    public function make(MessageChannel $channel): MessageSenderInterface
    {
        return match ($channel) {
            MessageChannel::Whatsapp => app(WhatsAppSender::class),
            MessageChannel::Messenger => app(MessengerSender::class),
            MessageChannel::Instagram => app(InstagramSender::class),
        };
    }
}
