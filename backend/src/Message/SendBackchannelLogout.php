<?php

namespace App\Message;

use Rocket\Core\Message\AsyncMessageInterface;

/**
 * Sends a logout token to an application (OpenID Connect Back-Channel Logout), in the background and retried
 * (messenger retry strategy of the "async" transport, then the "failed" transport).
 */
final class SendBackchannelLogout implements AsyncMessageInterface
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $subject,
        public readonly ?string $sid = null,
    ) {
    }
}
