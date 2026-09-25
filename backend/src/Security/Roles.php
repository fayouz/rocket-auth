<?php

namespace App\Security;

final class Roles
{
    public const ADMIN = 'ROLE_ADMIN';
    public const APPLICATION = 'ROLE_APPLICATION';
    public const IMPERSONATION = 'ROLE_IMPERSONATION';

    /**
     * Roles granted when a user is acted upon by an application: never elevated privileges.
     *
     * @param list<string> $userRoles
     *
     * @return list<string>
     */
    public static function delegated(array $userRoles, string ...$extra): array
    {
        $roles = array_filter($userRoles, static fn (string $role) => self::ADMIN !== $role);

        return array_values(array_unique([...$roles, ...$extra]));
    }
}
