<?php

namespace App\Controller;

use App\Entity\OAuthClient;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class OAuthClientSecretController extends AbstractController
{
    /** Rotates the secret of a confidential client: the previous one stops working immediately. */
    #[Route('/api/oauth/clients/{id}/regenerate-secret', name: 'api_oauth_client_regenerate_secret', methods: ['POST'])]
    #[IsGranted(Roles::ADMIN)]
    public function __invoke(OAuthClient $client, EntityManagerInterface $em): JsonResponse
    {
        if (!$client->isConfidential()) {
            return $this->json(['detail' => 'A public client has no secret.'], 422);
        }
        $secret = $client->rotateSecret();
        $em->flush();

        return $this->json(['secret' => $secret, 'secretHint' => $client->getSecretHint()]);
    }
}
