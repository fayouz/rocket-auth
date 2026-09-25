<?php

namespace App\Dashboard;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A domain module's contribution to the dashboard (GET /api/dashboard). Sections are merged in priority order.
 *
 * A section returns any of:
 * - "kpis": cards, each {id, label, value (int|float|null), format: number|percent|bytes, icon, tone,
 *   detail?, previous? (value over the previous period), series? (key of a daily series drawn as a sparkline),
 *   progress? (0-100), legend?: list<{label, color}>};
 * - "series": daily series {key, label, color} and "daily": [date (Y-m-d) => [key => int]];
 * - "recent": {title, link, empty, items: list<{id, title, subtitle, at, badge?, badgeColor?, link?}>};
 * - "activity": events {type, at (ATOM), title, actor, link, icon, label, color};
 * - "quickActions": list<{label, icon, to, tone}>.
 */
#[AutoconfigureTag('app.dashboard_section')]
interface DashboardSectionInterface
{
    /**
     * @param bool $admin platform-wide figures (administrators) or the user's own activity
     *
     * @return array<string, mixed>
     */
    public function build(User $user, bool $admin, \DateTimeImmutable $from, \DateTimeImmutable $previousFrom): array;
}
