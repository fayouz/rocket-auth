<?php

namespace App\EventListener;

use App\Entity\SignInEvent;
use Rocket\Core\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Records the sign-ins to Rocket Auth itself (password, directory or external provider) for the dashboard. */
#[AsEventListener(event: Events::AUTHENTICATION_SUCCESS)]
final class SignInListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();
        if ($user instanceof User) {
            $this->em->persist(new SignInEvent(SignInEvent::LOGIN, $user, null, $this->clock->now()));
            $this->em->flush();
        }
    }
}
