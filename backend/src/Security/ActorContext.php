<?php

namespace App\Security;

use App\Entity\Application;
use App\Entity\User;
use App\Repository\ApplicationRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Who is acting on the current request: a user, an application, or an application acting as a user.
 */
final class ActorContext
{
    public const APPLICATION_ATTRIBUTE = 'application_id';

    public function __construct(
        private readonly Security $security,
        private readonly ApplicationRepository $applications,
    ) {
    }

    public function getUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    public function requireUser(): User
    {
        return $this->getUser() ?? throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException('This action must be performed as a user (use X-Impersonate-User for applications).');
    }

    public function getApplication(): ?Application
    {
        $user = $this->security->getUser();
        if ($user instanceof ApplicationUser) {
            return $user->getApplication();
        }

        $token = $this->security->getToken();
        if (null === $token || !$token->hasAttribute(self::APPLICATION_ATTRIBUTE)) {
            return null;
        }

        return $this->applications->find($token->getAttribute(self::APPLICATION_ATTRIBUTE));
    }

}
