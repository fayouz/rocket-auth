<?php

namespace App\Controller;

use App\Security\ActorContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class MeController extends AbstractController
{
    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function __invoke(ActorContext $actor, Security $security): JsonResponse
    {
        $application = $actor->getApplication();

        return $this->json([
            'user' => $actor->getUser(),
            'application' => null === $application ? null : [
                'id' => $application->getId(),
                'name' => $application->getName(),
            ],
            // Effective roles for this session (an application acting as a user never gets ROLE_ADMIN).
            'roles' => $security->getToken()?->getRoleNames() ?? [],
        ], context: ['groups' => ['user:read']]);
    }
}
