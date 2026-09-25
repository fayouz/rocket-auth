<?php

namespace App\Dashboard;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Figures for the dashboard: the platform's own (users, applications, their activity) completed by the
 * domain sections (App\Dashboard\DashboardSectionInterface). Admins see the whole platform; other users their own activity.
 */
final class DashboardStats
{
    public const DAYS = 30;
    private const ACTIVITY_LIMIT = 12;

    /** @param iterable<DashboardSectionInterface> $sections */
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        #[AutowireIterator('app.dashboard_section')]
        private readonly iterable $sections,
    ) {
    }

    /** @return array<string, mixed> */
    public function forUser(User $user, bool $admin): array
    {
        $now = $this->clock->now();
        $from = $now->setTime(0, 0)->modify(\sprintf('-%d days', self::DAYS - 1));
        $previousFrom = $from->modify(\sprintf('-%d days', self::DAYS));

        $kpis = [];
        $series = [];
        $byDay = [];
        $activity = [];
        $recent = null;
        $quickActions = [];
        foreach ($this->sections as $section) {
            $part = $section->build($user, $admin, $from, $previousFrom);
            $kpis = [...$kpis, ...($part['kpis'] ?? [])];
            $series = [...$series, ...($part['series'] ?? [])];
            foreach ($part['daily'] ?? [] as $day => $values) {
                $byDay[$day] = ($byDay[$day] ?? []) + $values;
            }
            $activity = [...$activity, ...($part['activity'] ?? [])];
            $recent ??= $part['recent'] ?? null;
            $quickActions = [...$quickActions, ...($part['quickActions'] ?? [])];
        }

        $stats = [
            'scope' => $admin ? 'platform' : 'user',
            'generatedAt' => $now->format(\DATE_ATOM),
            'days' => self::DAYS,
            'kpis' => $kpis,
            'series' => $series,
            'daily' => $this->daily($byDay, $series, $from),
            'recent' => $recent,
            'quickActions' => $quickActions,
        ];

        if ($admin) {
            $users = array_map('intval', $this->db->fetchAssociative(
                "SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE enabled) AS enabled,
                        COUNT(*) FILTER (WHERE source = 'local') AS local, COUNT(*) FILTER (WHERE source = 'ldap') AS ldap,
                        COUNT(*) FILTER (WHERE source = 'oidc') AS oidc
                 FROM \"user\"",
            ));
            $stats['users'] = $users;
            $stats['kpis'][] = [
                'id' => 'users',
                'label' => 'Utilisateurs',
                'value' => $users['total'],
                'format' => 'number',
                'icon' => 'i-lucide-users',
                'tone' => 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
                'detail' => \sprintf('%d actif(s)', $users['enabled']),
                'legend' => [
                    ['label' => $users['local'].' locaux', 'color' => 'bg-sky-500'],
                    ['label' => $users['ldap'].' LDAP', 'color' => 'bg-violet-500'],
                    ['label' => $users['oidc'].' SSO', 'color' => 'bg-primary'],
                ],
            ];
            $stats['applications'] = $this->applications();
            $activity = [...$activity, ...$this->platformActivity()];
        }

        usort($activity, static fn (array $a, array $b) => strcmp((string) $b['at'], (string) $a['at']));
        $stats['activity'] = \array_slice($activity, 0, self::ACTIVITY_LIMIT);

        return $stats;
    }

    /**
     * One entry per day, oldest first, including days without data (every series at 0).
     *
     * @param array<string, array<string, int>>                        $byDay
     * @param list<array{key: string, label: string, color: string}> $series
     *
     * @return list<array<string, int|string>>
     */
    private function daily(array $byDay, array $series, \DateTimeImmutable $from): array
    {
        $keys = array_column($series, 'key');
        $daily = [];
        for ($i = 0; $i < self::DAYS; ++$i) {
            $day = $from->modify("+$i days")->format('Y-m-d');
            $row = ['date' => $day];
            foreach ($keys as $key) {
                $row[$key] = (int) ($byDay[$day][$key] ?? 0);
            }
            $daily[] = $row;
        }

        return $daily;
    }

    /** @return list<array<string, mixed>> */
    private function platformActivity(): array
    {
        $events = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT email, source, created_at, created_by FROM "user" ORDER BY created_at DESC LIMIT '.self::ACTIVITY_LIMIT,
        ) as $row) {
            $events[] = [
                'type' => 'user.created',
                'at' => self::atom($row['created_at']),
                'title' => $row['email'].match ($row['source']) { 'ldap' => ' (LDAP)', 'oidc' => ' (SSO)', default => '' },
                'actor' => $row['created_by'],
                'link' => '/users',
                'icon' => 'i-lucide-user-plus',
                'label' => 'Nouvel utilisateur',
                'color' => 'text-emerald-600 bg-emerald-500/10 dark:text-emerald-400',
            ];
        }
        foreach ($this->db->fetchAllAssociative(
            'SELECT name, created_at, created_by FROM application ORDER BY created_at DESC LIMIT '.self::ACTIVITY_LIMIT,
        ) as $row) {
            $events[] = [
                'type' => 'application.created',
                'at' => self::atom($row['created_at']),
                'title' => $row['name'],
                'actor' => $row['created_by'],
                'link' => '/applications',
                'icon' => 'i-lucide-plug',
                'label' => 'Nouvelle application',
                'color' => 'text-violet-600 bg-violet-500/10 dark:text-violet-400',
            ];
        }

        return $events;
    }

    /** @return list<array<string, mixed>> */
    private function applications(): array
    {
        return array_map(static fn (array $row) => [
            'id' => $row['id'],
            'name' => $row['name'],
            'enabled' => (bool) $row['enabled'],
            'canImpersonate' => (bool) $row['can_impersonate'],
            'lastUsedAt' => self::atom($row['last_used_at']),
        ], $this->db->fetchAllAssociative(
            'SELECT id, name, enabled, can_impersonate, last_used_at FROM application ORDER BY last_used_at DESC NULLS LAST, name',
        ));
    }

    public static function sql(\DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public static function atom(?string $value): ?string
    {
        return null === $value ? null : (new \DateTimeImmutable($value))->format(\DATE_ATOM);
    }
}
