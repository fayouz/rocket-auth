<?php

namespace App\OAuth;

use Rocket\Core\Entity\User;

/** The scopes Rocket Auth understands, their description (consent screen) and the claims they release. */
final class Scopes
{
    public const ALL = ['openid', 'profile', 'email', 'groups', 'offline_access'];

    public const DESCRIPTIONS = [
        'openid' => 'Vous identifier (identifiant unique de votre compte)',
        'profile' => 'Connaître votre nom et prénom',
        'email' => 'Connaître votre adresse email',
        'groups' => 'Connaître vos groupes',
        'offline_access' => 'Rester connecté en votre absence',
    ];

    /** @return list<string> */
    public static function parse(?string $scope): array
    {
        return array_values(array_unique(array_filter(preg_split('/\s+/', trim((string) $scope)) ?: [], static fn (string $s) => '' !== $s)));
    }

    /**
     * Claims about the user released for the granted scopes (ID token and userinfo).
     *
     * @param list<string> $scopes
     *
     * @return array<string, mixed>
     */
    public static function claims(User $user, array $scopes): array
    {
        $claims = ['sub' => (string) $user->getId()];
        if (\in_array('profile', $scopes, true)) {
            $claims += [
                'name' => $user->getDisplayName(),
                'given_name' => $user->getFirstName(),
                'family_name' => $user->getLastName(),
                'preferred_username' => $user->getEmail(),
                'updated_at' => ($user->getUpdatedAt() ?? $user->getCreatedAt())?->getTimestamp(),
            ];
        }
        if (\in_array('email', $scopes, true)) {
            // Accounts are created by administrators or come from the directory: their address is trusted.
            $claims += ['email' => $user->getEmail(), 'email_verified' => true];
        }
        if (\in_array('groups', $scopes, true)) {
            $claims['groups'] = $user->getGroups();
        }

        return array_filter($claims, static fn (mixed $value) => null !== $value);
    }
}
