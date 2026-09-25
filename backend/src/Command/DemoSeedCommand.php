<?php

namespace App\Command;

use App\Entity\Application;
use App\Entity\AuthenticationServer;
use App\Entity\User;
use App\Enum\AuthenticationServerType;
use App\Repository\ApplicationRepository;
use App\Repository\AuthenticationServerRepository;
use App\Repository\UserRepository;
use App\Security\Roles;
use App\Security\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Idempotent demo data. Refuses to run unless DEMO_MODE=1, so it can never touch a real instance.
 * With DEMO_SSO_ISSUER, also declares the demo's single sign-on provider (Rocket Auth).
 */
#[AsCommand(name: 'app:demo:seed', description: 'Load demo users, an external application and the domain demo data (DEMO_MODE=1 only).')]
final class DemoSeedCommand
{
    public const USERS = [
        ['admin@example.org', 'demo-admin-password', 'Ada', 'Admin', true],
        ['alice@example.org', 'demo-alice-password', 'Alice', 'Durand', false],
    ];

    /** @param iterable<DemoSeederInterface> $seeders */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly ApplicationRepository $applications,
        private readonly AuthenticationServerRepository $servers,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly SecretBox $secrets,
        #[AutowireIterator('app.demo_seeder')] private readonly iterable $seeders,
        #[Autowire(env: 'bool:DEMO_MODE')] private readonly bool $demoMode,
        #[Autowire(env: 'DEMO_APP_TOKEN')] private readonly string $demoAppToken,
        #[Autowire(env: 'DEMO_SSO_ISSUER')] private readonly string $ssoIssuer = '',
        #[Autowire(env: 'DEMO_SSO_INTERNAL_URL')] private readonly string $ssoInternalUrl = '',
        #[Autowire(env: 'DEMO_SSO_CLIENT_ID')] private readonly string $ssoClientId = '',
        #[Autowire(env: 'DEMO_SSO_CLIENT_SECRET')] #[\SensitiveParameter] private readonly string $ssoClientSecret = '',
        #[Autowire(env: 'DEMO_SSO_ADMIN_GROUP')] private readonly string $ssoAdminGroup = '',
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if (!$this->demoMode) {
            $io->error('Demo data can only be loaded with DEMO_MODE=1.');

            return Command::FAILURE;
        }

        $seeded = [];
        foreach (self::USERS as [$email, $password, $firstName, $lastName, $isAdmin]) {
            $user = $this->users->findOneBy(['email' => $email]) ?? new User();
            $user->setEmail($email)->setFirstName($firstName)->setLastName($lastName)
                ->setRoles($isAdmin ? [Roles::ADMIN] : [])->setEnabled(true);
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $this->em->persist($user);
            $seeded[$email] = $user;
        }
        $this->em->flush();
        $admin = reset($seeded);

        // Attribute the demo content to the admin (Blameable reads the security token).
        $this->tokenStorage->setToken(new PostAuthenticationToken($admin, 'api', $admin->getRoles()));

        $application = $this->applications->findOneBy(['name' => 'Application de démo']) ?? (new Application())->setName('Application de démo');
        $application->setDescription('Application tierce de démonstration, autorisée à agir au nom des utilisateurs.')
            ->setCanImpersonate(true)
            ->setEnabled(true);
        if ('' !== $this->demoAppToken) {
            $application->useToken($this->demoAppToken);
        }
        $this->em->persist($application);

        if ('' !== $this->ssoIssuer) {
            $server = $this->servers->findOneBy(['type' => AuthenticationServerType::Oidc, 'url' => rtrim($this->ssoIssuer, '/')]) ?? new AuthenticationServer();
            $server->setName('Rocket Auth')
                ->setType(AuthenticationServerType::Oidc)
                ->setEnabled(true)
                ->setUrl(rtrim($this->ssoIssuer, '/'))
                ->setInternalUrl($this->ssoInternalUrl)
                ->setClientId($this->ssoClientId)
                ->setEncryptedClientSecret($this->secrets->encrypt($this->ssoClientSecret))
                ->setScopes('openid email profile groups')
                ->setAdminGroupDn($this->ssoAdminGroup)
                // Same demo users on every application of the suite.
                ->setLinkExistingAccounts(true);
            $this->em->persist($server);
        }
        $this->em->flush();

        foreach ($this->seeders as $seeder) {
            $seeder->seed($seeded, $io);
        }
        $this->em->flush();
        $this->tokenStorage->setToken(null);

        $io->success('Demo data loaded.');
        $io->table(['Compte', 'Mot de passe'], array_map(static fn (array $u) => [$u[0], $u[1]], self::USERS));
        if ('' !== $this->ssoIssuer) {
            $io->text(\sprintf('Authentification unique : %s (client %s)', $this->ssoIssuer, $this->ssoClientId));
        }

        return Command::SUCCESS;
    }
}
