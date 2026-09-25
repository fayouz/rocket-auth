<?php

namespace App\Controller;

use App\Dashboard\DashboardStats;
use App\Dashboard\PlatformHealth;
use App\Security\ActorContext;
use App\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    /**
     * Platform-wide figures and service details for admins; the user's own activity otherwise.
     * Not available to applications (ScopeGuardListener).
     */
    #[Route('/api/dashboard', name: 'api_dashboard', methods: ['GET'])]
    public function __invoke(ActorContext $actor, DashboardStats $stats, PlatformHealth $health): JsonResponse
    {
        $admin = $this->isGranted(Roles::ADMIN);
        $data = $stats->forUser($actor->requireUser(), $admin);

        $check = $health->check();
        // Everyone sees the overall status; service details (hosts, disk usage) are for admins.
        $data['health'] = $admin ? $check : ['status' => $check['status']];

        return $this->json($data);
    }
}
