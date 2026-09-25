<?php

namespace App\Controller;

use App\Ldap\LdapUserSynchronizer;
use App\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class LdapSyncController extends AbstractController
{
    #[Route('/api/ldap/sync', name: 'api_ldap_sync', methods: ['POST'])]
    #[IsGranted(Roles::ADMIN)]
    public function __invoke(LdapUserSynchronizer $synchronizer, #[MapQueryParameter] bool $dryRun = false): JsonResponse
    {
        try {
            return $this->json($synchronizer->sync($dryRun)->toArray());
        } catch (\LogicException|\RuntimeException $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }
    }
}
