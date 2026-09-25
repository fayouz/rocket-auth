<?php

namespace App\Tests\Integration;

use App\Entity\User;
use App\Enum\UserSource;
use App\Ldap\DirectoryUser;
use App\Ldap\LdapDirectory;
use App\Ldap\LdapUserSynchronizer;
use App\Ldap\UserDirectoryInterface;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class LdapUserSynchronizerTest extends KernelTestCase
{
    /** @param list<DirectoryUser> $entries */
    private function synchronizer(array $entries): LdapUserSynchronizer
    {
        $directory = new class($entries) implements UserDirectoryInterface {
            public function __construct(private array $entries)
            {
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function checkCredentials(string $dn, string $password): bool
            {
                return false;
            }

            public function fetchUsers(): iterable
            {
                return $this->entries;
            }

            public function probe(\App\Ldap\LdapConfig $config, int $limit = 5): array
            {
                return ['count' => \count($this->entries), 'sample' => \array_slice($this->entries, 0, $limit)];
            }

            public function ping(\App\Ldap\LdapConfig $config): void
            {
            }
        };

        $container = static::getContainer();

        return new LdapUserSynchronizer(
            $directory,
            $container->get(UserRepository::class),
            $container->get(EntityManagerInterface::class),
            new LockFactory(new InMemoryStore()),
        );
    }

    public function testCreatesUpdatesAndDisablesDirectoryUsers(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $report = $this->synchronizer([
            new DirectoryUser('uid=alice,ou=people,dc=ex', 'Alice@Example.org', 'Alice', 'Martin', admin: true),
            new DirectoryUser('uid=bob,ou=people,dc=ex', 'bob@example.org', 'Bob'),
        ])->sync();
        self::assertSame(2, $report->created);

        $alice = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'alice@example.org']);
        self::assertSame(UserSource::Ldap, $alice->getSource());
        self::assertContains('ROLE_ADMIN', $alice->getRoles());
        self::assertNull($alice->getPassword());

        // Bob left the directory, Alice lost the admin group.
        $report = $this->synchronizer([
            new DirectoryUser('uid=alice,ou=people,dc=ex', 'alice@example.org', 'Alice', 'Dupont', admin: false),
        ])->sync();
        self::assertSame(['created' => 0, 'updated' => 1, 'disabled' => 1], array_intersect_key($report->toArray(), array_flip(['created', 'updated', 'disabled'])));

        $em->clear();
        $users = static::getContainer()->get(UserRepository::class);
        self::assertFalse($users->findOneBy(['email' => 'bob@example.org'])->isEnabled());
        $alice = $users->findOneBy(['email' => 'alice@example.org']);
        self::assertSame('Dupont', $alice->getLastName());
        self::assertNotContains('ROLE_ADMIN', $alice->getRoles());
    }

    public function testNeverTakesOverLocalAccounts(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new User())->setEmail('admin@example.org')->setPassword('hash')->setRoles(['ROLE_ADMIN']));
        $em->flush();

        $report = $this->synchronizer([new DirectoryUser('uid=admin,dc=ex', 'admin@example.org')])->sync();

        self::assertSame(['admin@example.org'], $report->conflicts);
        $em->clear();
        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'admin@example.org']);
        self::assertSame(UserSource::Local, $admin->getSource());
        self::assertSame('hash', $admin->getPassword());
    }

    public function testDryRunWritesNothing(): void
    {
        self::bootKernel();

        $report = $this->synchronizer([new DirectoryUser('uid=carol,dc=ex', 'carol@example.org')])->sync(dryRun: true);

        self::assertSame(1, $report->created);
        self::assertNull(static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'carol@example.org']));
    }

    public function testEmptyPasswordNeverReachesTheDirectory(): void
    {
        // An empty password would be an anonymous bind; it must be refused before any connection attempt.
        $settings = new class extends \App\Ldap\LdapSettings {
            public function __construct()
            {
            }

            public function get(): \App\Ldap\LdapConfig
            {
                return new \App\Ldap\LdapConfig(enabled: true, url: 'ldap://unreachable.invalid', baseDn: 'dc=ex');
            }
        };
        $directory = new LdapDirectory($settings);

        self::assertFalse($directory->checkCredentials('uid=alice,dc=ex', ''));
        self::assertFalse($directory->checkCredentials('', 'secret'));
    }
}
