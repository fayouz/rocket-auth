<?php

namespace App\Controller;

use App\Setup\FirstRunSetup;
use App\Setup\SetupInput;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/** First-run setup (public): creates the first administrator, then logs them in. */
final class SetupController extends AbstractController
{
    public function __construct(private readonly FirstRunSetup $setup)
    {
    }

    #[Route('/api/setup', name: 'api_setup_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $required = $this->setup->isRequired();

        return $this->json(['required' => $required, 'tokenRequired' => $required && $this->setup->isTokenRequired()]);
    }

    #[Route('/api/setup', name: 'api_setup', methods: ['POST'])]
    public function setup(#[MapRequestPayload] SetupInput $input, JWTTokenManagerInterface $jwt): JsonResponse
    {
        $user = $this->setup->createAdministrator($input);

        return $this->json(['token' => $jwt->create($user), 'email' => $user->getEmail()], 201);
    }
}
