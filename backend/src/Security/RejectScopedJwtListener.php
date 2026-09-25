<?php

namespace App\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A scoped token must never be accepted as a full user session through "Bearer".
 */
#[AsEventListener(event: Events::JWT_DECODED)]
final class RejectScopedJwtListener
{
    public function __invoke(JWTDecodedEvent $event): void
    {
        if (\array_key_exists('scope', $event->getPayload())) {
            $event->markAsInvalid();
        }
    }
}
