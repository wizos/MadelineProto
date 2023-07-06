<?php declare(strict_types=1);

namespace danog\MadelineProto\EventHandler\Message;

use danog\MadelineProto\EventHandler\BasicFilter\Incoming;
use danog\MadelineProto\MTProto;

/**
 * Represents an incoming channel message.
 */
final class IncomingChannelMessage extends ChannelMessage implements Incoming
{
    /** @internal */
    public function __construct(
        MTProto $API,
        array $rawMessage
    ) {
        parent::__construct($API, $rawMessage, false);
    }
}