<?php

namespace App\EventListener;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Each Rocket Auth session (session JWT) gets an identifier, "sid": the applications the user signs in to during this
 * session are recorded with it, so that signing out ends exactly those sign-ins (see BackchannelLogout).
 */
#[AsEventListener(event: Events::JWT_CREATED)]
final class SessionIdListener
{
    public function __invoke(JWTCreatedEvent $event): void
    {
        $data = $event->getData();
        if (!isset($data['sid']) && !isset($data['scope'])) {
            $data['sid'] = bin2hex(random_bytes(16));
            $event->setData($data);
        }
    }
}
