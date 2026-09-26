<?php

namespace App\Command;

use Rocket\Core\Command\DemoSeederInterface;
use App\Entity\OAuthClient;
use App\Repository\OAuthClientRepository;
use Rocket\Core\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Demo OAuth clients: the applications of the Rocket middleware (DEMO_OAUTH_CLIENTS), each with a known secret,
 * plus an admin group for the demo accounts.
 *
 * DEMO_OAUTH_CLIENTS: "clientId|Name|secret|redirect URI|post-logout URI|home URL|icon|back-channel logout URI" entries
 * separated by ";" (the home URL and the icon put the application in the switcher of the suite; the back-channel logout
 * URI is optional: the bricks declare it themselves). They may call each other (client credentials).
 */
final class OAuthDemoSeeder implements DemoSeederInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OAuthClientRepository $clients,
        private readonly UserRepository $users,
        #[Autowire(env: 'DEMO_OAUTH_CLIENTS')] private readonly string $demoClients = '',
    ) {
    }

    public function seed(array $users, SymfonyStyle $io): void
    {
        // Demo administrators are administrators of every application of the suite ("groups" claim).
        foreach ($users as $user) {
            if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                $user->setGroups([...$user->getGroups(), 'rocket-admins']);
            }
        }

        $rows = [];
        foreach (array_filter(array_map('trim', explode(';', $this->demoClients))) as $entry) {
            [$clientId, $name, $secret, $redirectUri, $logoutUri, $homeUrl, $icon, $backchannelUri] = array_map('trim', explode('|', $entry)) + [3 => '', 4 => '', 5 => '', 6 => '', 7 => ''];
            $client = $this->clients->findOneBy(['clientId' => $clientId]) ?? (new OAuthClient())->setClientId($clientId);
            $client->setName($name)
                ->setDescription('Application de démonstration de la couche Middleware Rocket.')
                ->setConfidential(true)
                ->setRedirectUris(array_filter([$redirectUri]))
                ->setPostLogoutRedirectUris(array_filter([$logoutUri]))
                ->setHomeUrl($homeUrl)
                ->setIcon($icon)
                ->setAllowedScopes(['openid', 'email', 'profile', 'groups', 'offline_access'])
                // Calls between the bricks: tokens of the application for itself.
                ->setGrantTypes(['authorization_code', 'refresh_token', 'client_credentials'])
                // Applications of the same organization: no consent screen.
                ->setTrusted(true)
                ->setEnabled(true);
            if ('' !== $backchannelUri) {
                $client->setBackchannelLogoutUri($backchannelUri);
            }
            $client->useSecret($secret);
            $this->em->persist($client);
            $rows[] = [$clientId, $name, $redirectUri];
        }
        $this->em->flush();

        if ($rows) {
            $io->table(['Client OAuth', 'Application', 'Redirect URI'], $rows);
        }
    }
}
