<?php

namespace App\Security;

use App\Entity\Application;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Security identity of an application calling the API without impersonating anyone.
 */
final class ApplicationUser implements UserInterface
{
    public function __construct(private readonly Application $application)
    {
    }

    public function getApplication(): Application
    {
        return $this->application;
    }

    public function getRoles(): array
    {
        return [Roles::APPLICATION];
    }

    public function getUserIdentifier(): string
    {
        return 'app:'.$this->application->getId()->toRfc4122();
    }
}
