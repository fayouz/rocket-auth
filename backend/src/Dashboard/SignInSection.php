<?php

namespace App\Dashboard;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Rocket Auth on the dashboard: sign-ins, authorizations granted to applications, active sessions and clients.
 * Admins see the whole platform; users their own sign-ins and the applications they authorized.
 */
final class SignInSection implements DashboardSectionInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    public function build(User $user, bool $admin, \DateTimeImmutable $from, \DateTimeImmutable $previousFrom): array
    {
        [$scope, $params] = $admin ? ['TRUE', []] : ['e.user_id = :user', ['user' => $user->getId()->toRfc4122()]];
        $now = $this->clock->now();

        $daily = [];
        foreach ($this->db->fetchAllAssociative(
            "SELECT to_char(date_trunc('day', e.at), 'YYYY-MM-DD') AS day,
                    COUNT(*) FILTER (WHERE e.type = 'login') AS login,
                    COUNT(*) FILTER (WHERE e.type = 'authorize') AS authorize,
                    COUNT(*) FILTER (WHERE e.type = 'denied') AS denied
             FROM sign_in_event e WHERE $scope AND e.at >= :from GROUP BY 1",
            $params + ['from' => DashboardStats::sql($from)],
        ) as $row) {
            $daily[$row['day']] = ['login' => (int) $row['login'], 'authorize' => (int) $row['authorize'], 'denied' => (int) $row['denied']];
        }
        $previous = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM sign_in_event e WHERE $scope AND e.type = 'authorize' AND e.at >= :from AND e.at < :to",
            $params + ['from' => DashboardStats::sql($previousFrom), 'to' => DashboardStats::sql($from)],
        );
        $authorizations = array_sum(array_column($daily, 'authorize'));
        $logins = array_sum(array_column($daily, 'login'));

        $sessions = $this->db->fetchAssociative(
            'SELECT COUNT(*) AS tokens, COUNT(DISTINCT r.user_id) AS users, COUNT(DISTINCT r.client_id) AS clients
             FROM refresh_token r WHERE r.revoked_at IS NULL AND r.expires_at > :now'.($admin ? '' : ' AND r.user_id = :user'),
            ['now' => DashboardStats::sql($now)] + ($admin ? [] : $params),
        );

        $kpis = [
            [
                'id' => 'authorizations',
                'label' => 'Connexions aux applications (30 j)',
                'value' => $authorizations,
                'format' => 'number',
                'icon' => 'i-lucide-log-in',
                'tone' => 'bg-primary/10 text-primary',
                'series' => 'authorize',
                'previous' => $previous,
            ],
            [
                'id' => 'logins',
                'label' => 'Connexions à Rocket Auth (30 j)',
                'value' => $logins,
                'format' => 'number',
                'icon' => 'i-lucide-key-round',
                'tone' => 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
                'series' => 'login',
            ],
            [
                'id' => 'sessions',
                'label' => $admin ? 'Sessions applicatives actives' : 'Mes sessions actives',
                'value' => (int) $sessions['tokens'],
                'format' => 'number',
                'icon' => 'i-lucide-monitor-smartphone',
                'tone' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
                'detail' => \sprintf('%d utilisateur(s), %d application(s)', $sessions['users'], $sessions['clients']),
            ],
        ];

        if ($admin) {
            $clients = $this->db->fetchAssociative('SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE enabled) AS enabled, COUNT(*) FILTER (WHERE trusted) AS trusted FROM oauth_client');
            $kpis[] = [
                'id' => 'clients',
                'label' => 'Clients OAuth',
                'value' => (int) $clients['total'],
                'format' => 'number',
                'icon' => 'i-lucide-app-window',
                'tone' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
                'detail' => \sprintf('%d actif(s)', $clients['enabled']),
                'legend' => [['label' => $clients['trusted'].' de confiance', 'color' => 'bg-amber-500']],
            ];
        }

        return [
            'kpis' => $kpis,
            'series' => [
                ['key' => 'authorize', 'label' => 'Autorisations', 'color' => 'bg-primary'],
                ['key' => 'login', 'label' => 'Connexions', 'color' => 'bg-sky-500'],
                ['key' => 'denied', 'label' => 'Refus', 'color' => 'bg-error'],
            ],
            'daily' => $daily,
            'recent' => [
                'title' => $admin ? 'Dernières connexions aux applications' : 'Mes dernières connexions',
                'link' => $admin ? '/clients' : '/consents',
                'empty' => 'Aucune connexion pour le moment.',
                'items' => $this->recent($scope, $params),
            ],
            'activity' => $this->activity($admin, $scope, $params),
            'quickActions' => $admin
                ? [['label' => 'Nouveau client OAuth', 'icon' => 'i-lucide-app-window', 'to' => '/clients?new=1', 'tone' => 'bg-primary/10 text-primary']]
                : [['label' => 'Applications autorisées', 'icon' => 'i-lucide-shield-check', 'to' => '/consents', 'tone' => 'bg-primary/10 text-primary']],
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private function recent(string $scope, array $params): array
    {
        return array_map(static fn (array $row) => [
            'id' => $row['id'],
            'title' => match ($row['type']) {
                'login' => 'Rocket Auth',
                default => $row['client_name'] ?? 'Application supprimée',
            },
            'subtitle' => $row['email'] ?? '—',
            'at' => DashboardStats::atom($row['at']),
            'badge' => match ($row['type']) { 'authorize' => 'Autorisée', 'denied' => 'Refusée', default => 'Connexion' },
            'badgeColor' => match ($row['type']) { 'authorize' => 'success', 'denied' => 'error', default => 'neutral' },
        ], $this->db->fetchAllAssociative(
            "SELECT e.id, e.type, e.at, u.email, c.name AS client_name
             FROM sign_in_event e LEFT JOIN \"user\" u ON u.id = e.user_id LEFT JOIN oauth_client c ON c.id = e.client_id
             WHERE $scope ORDER BY e.at DESC, e.id DESC LIMIT 6",
            $params,
        ));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private function activity(bool $admin, string $scope, array $params): array
    {
        $events = [];
        foreach ($this->db->fetchAllAssociative(
            "SELECT e.type, e.at, u.email, c.name AS client_name
             FROM sign_in_event e LEFT JOIN \"user\" u ON u.id = e.user_id LEFT JOIN oauth_client c ON c.id = e.client_id
             WHERE $scope AND e.type <> 'login' ORDER BY e.at DESC LIMIT 8",
            $params,
        ) as $row) {
            $denied = 'denied' === $row['type'];
            $events[] = [
                'type' => 'oauth.'.$row['type'],
                'at' => DashboardStats::atom($row['at']),
                'title' => $row['client_name'] ?? 'Application supprimée',
                'actor' => $row['email'],
                'link' => null,
                'icon' => $denied ? 'i-lucide-shield-x' : 'i-lucide-log-in',
                'label' => $denied ? 'Autorisation refusée' : 'Connexion à une application',
                'color' => $denied ? 'text-error bg-error/10' : 'text-primary bg-primary/10',
            ];
        }
        if ($admin) {
            foreach ($this->db->fetchAllAssociative('SELECT name, created_at, created_by FROM oauth_client ORDER BY created_at DESC LIMIT 8') as $row) {
                $events[] = [
                    'type' => 'oauth_client.created',
                    'at' => DashboardStats::atom($row['created_at']),
                    'title' => $row['name'],
                    'actor' => $row['created_by'],
                    'link' => '/clients',
                    'icon' => 'i-lucide-app-window',
                    'label' => 'Nouveau client OAuth',
                    'color' => 'text-amber-600 bg-amber-500/10 dark:text-amber-400',
                ];
            }
        }

        return $events;
    }
}
