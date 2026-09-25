<?php

namespace App\Controller;

use App\Ldap\LdapDiscovery;
use App\Ldap\LdapSettings;
use App\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(Roles::ADMIN)]
final class AuthenticationServerDiscoveryController extends AbstractController
{
    #[Route('/api/authentication_servers/discover', name: 'api_authentication_servers_discover', methods: ['GET'], priority: 10)]
    public function __invoke(LdapDiscovery $discovery, LdapSettings $settings): JsonResponse
    {
        return $this->json(['servers' => $discovery->discover($settings->get()->url)]);
    }
}
