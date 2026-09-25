<?php

namespace App\Controller;

use App\Repository\OAuthClientRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public: the applications of the suite, for the application switcher of every Rocket application in suite mode
 * (rocket-core, Rocket\Core\Suite\SuiteApps): the enabled clients that have an address.
 */
final class SuiteAppsController extends AbstractController
{
    #[Route('/api/suite/apps', name: 'api_suite_apps', methods: ['GET'])]
    public function __invoke(OAuthClientRepository $clients): JsonResponse
    {
        $apps = [];
        foreach ($clients->findBy(['enabled' => true], ['name' => 'ASC']) as $client) {
            if (null === $client->getHomeUrl()) {
                continue;
            }
            $apps[] = [
                'id' => preg_replace('/^rocket-/', '', $client->getClientId()),
                'name' => $client->getName(),
                'url' => $client->getHomeUrl(),
                'icon' => $client->getIcon(),
                'description' => $client->getDescription(),
            ];
        }

        $response = $this->json(['apps' => $apps]);
        $response->setPublic()->setMaxAge(60);

        return $response;
    }
}
