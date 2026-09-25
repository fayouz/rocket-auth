<?php

namespace App\Oidc;

use App\Entity\AuthenticationServer;
use App\Entity\User;
use App\Enum\UserSource;
use App\Repository\UserRepository;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds or creates the account of an OpenID Connect identity, and keeps its profile in sync with the claims.
 * An existing account with the same email is only linked when the provider is trusted for it (linkExistingAccounts).
 */
class OidcAccountLinker
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @param array<string, mixed> $claims */
    public function resolve(AuthenticationServer $server, array $claims): User
    {
        $subject = (string) $claims['sub'];
        $email = \is_string($claims['email'] ?? null) ? mb_strtolower(trim($claims['email'])) : '';

        $user = $this->users->findOneBy(['authenticationServer' => $server, 'externalId' => $subject]);
        if (null === $user) {
            if ('' === $email || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                throw new OidcException('The authentication server did not provide an email address (scope "email").');
            }
            if (false === ($claims['email_verified'] ?? true)) {
                throw new OidcException('This email address is not verified by the authentication server.');
            }
            $user = $this->users->findOneBy(['email' => $email]);
            if (null !== $user && (!$server->isLinkExistingAccounts() || null !== $user->getExternalId())) {
                throw new OidcException('An account already uses this email address. Sign in with it, or ask an administrator to link the accounts.');
            }
            if (null === $user) {
                $user = (new User())->setEmail($email)->setSource(UserSource::Oidc);
                $this->em->persist($user);
            }
            $user->setAuthenticationServer($server)->setExternalId($subject);
        }

        if (!$user->isEnabled()) {
            throw new OidcException('This account is disabled.');
        }

        if (UserSource::Oidc === $user->getSource()) {
            if ('' !== $email && $email !== $user->getEmail() && null === $this->users->findOneBy(['email' => $email])) {
                $user->setEmail($email);
            }
            if (\is_string($claims['given_name'] ?? null)) {
                $user->setFirstName($claims['given_name']);
            }
            if (\is_string($claims['family_name'] ?? null)) {
                $user->setLastName($claims['family_name']);
            }
        }

        if ('' !== $server->getAdminGroupDn() && \array_key_exists('groups', $claims)) {
            $isAdmin = \in_array($server->getAdminGroupDn(), (array) $claims['groups'], true);
            $roles = array_values(array_filter($user->getRoles(), static fn (string $role) => Roles::ADMIN !== $role));
            $user->setRoles($isAdmin ? [...$roles, Roles::ADMIN] : $roles);
        }

        $this->em->flush();

        return $user;
    }
}
