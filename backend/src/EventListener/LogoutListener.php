<?php

namespace App\EventListener;

use App\OAuth\BackchannelLogout;
use Rocket\Core\Event\UserLoggedOutEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Signing out of Rocket Auth (its interface, or /oauth/logout from an application: RP-initiated logout) ends the
 * sign-ins of that session in the applications: refresh tokens revoked, logout tokens sent (back-channel logout).
 */
#[AsEventListener]
final class LogoutListener
{
    public function __construct(private readonly BackchannelLogout $logout)
    {
    }

    public function __invoke(UserLoggedOutEvent $event): void
    {
        $sid = $event->session['sid'] ?? null;
        $this->logout->session((string) $event->user->getId(), \is_string($sid) && '' !== $sid ? $sid : null);
    }
}
