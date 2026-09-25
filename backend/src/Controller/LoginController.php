<?php

namespace App\Controller;

use Symfony\Component\Routing\Attribute\Route;

final class LoginController
{
    /** Handled by App\Security\LoginAuthenticator; the route only has to exist for the router. */
    #[Route('/api/auth/login', name: 'api_auth_login', methods: ['POST'])]
    public function __invoke(): never
    {
        throw new \LogicException('This endpoint is handled by the login firewall.');
    }
}
