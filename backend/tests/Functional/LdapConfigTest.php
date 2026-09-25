<?php

namespace App\Tests\Functional;

use App\Ldap\DirectoryUser;
use App\Ldap\LdapConfig;
use App\Ldap\UserDirectoryInterface;
use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LdapConfigTest extends WebTestCase
{
    use ApiTestTrait;

    private function admin(): string
    {
        return 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
    }

    private function settings(array $overrides = []): array
    {
        return $overrides + [
            'enabled' => true,
            'url' => 'ldap://ldap.example.org:389',
            'startTls' => true,
            'baseDn' => 'ou=people,dc=example,dc=org',
            'bindDn' => 'cn=reader,dc=example,dc=org',
            'bindPassword' => 's3cret-bind',
            'userFilter' => '(objectClass=inetOrgPerson)',
            'adminGroupDn' => 'cn=admins,ou=groups,dc=example,dc=org',
            'attributes' => ['email' => 'mail', 'firstName' => 'givenName', 'lastName' => 'sn', 'groups' => 'memberOf'],
        ];
    }

    public function testConfigurationIsStoredInTheDatabaseWithoutLeakingThePassword(): void
    {
        $admin = $this->admin();

        // Nothing saved yet: the LDAP_* environment variables (disabled in tests).
        $initial = $this->api('GET', '/api/ldap/config', authorization: $admin);
        $this->assertStatus(200);
        self::assertSame('environment', $initial['source']);
        self::assertFalse($initial['enabled']);

        $saved = $this->api('PUT', '/api/ldap/config', $this->settings(), $admin);
        $this->assertStatus(200);
        self::assertSame('database', $saved['source']);
        self::assertTrue($saved['enabled']);
        self::assertTrue($saved['hasBindPassword']);
        self::assertStringNotContainsString('s3cret-bind', json_encode($saved));

        $stored = $this->em()->getConnection()->fetchOne("SELECT bind_password FROM authentication_server WHERE type = 'ldap'");
        self::assertStringNotContainsString('s3cret-bind', $stored);
        self::assertStringContainsString('v1:', $stored);

        // An empty password keeps the stored one.
        $this->api('PUT', '/api/ldap/config', $this->settings(['bindPassword' => '', 'userFilter' => '(objectClass=person)']), $admin);
        $this->assertStatus(200);
        $config = static::getContainer()->get(\App\Ldap\LdapSettings::class);
        $config->reset();
        self::assertSame('s3cret-bind', $config->get()->bindPassword);
        self::assertSame('(objectClass=person)', $config->get()->userFilter);

        $this->api('PUT', '/api/ldap/config', $this->settings(['url' => 'ldaps://ldap.example.org', 'startTls' => true]), $admin);
        $this->assertStatus(422);
        $this->api('PUT', '/api/ldap/config', $this->settings(['baseDn' => '']), $admin);
        $this->assertStatus(422);

        // Back to the .env defaults.
        $reset = $this->api('DELETE', '/api/ldap/config', authorization: $admin);
        $this->assertStatus(200);
        self::assertSame('environment', $reset['source']);
        self::assertFalse($reset['enabled']);
        self::assertSame(LdapConfig::DEFAULT_ATTRIBUTES, $reset['attributes']);
        $this->api('PUT', '/api/ldap/config', $this->settings(), $admin);

        // The dashboard reads it too.
        $services = array_column($this->api('GET', '/api/dashboard', authorization: $admin)['health']['services'], null, 'id');
        self::assertSame('ldap://ldap.example.org:389', $services['ldap']['detail']);

        $alice = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));
        $this->api('GET', '/api/ldap/config', authorization: $alice);
        $this->assertStatus(403);
        $this->api('PUT', '/api/ldap/config', $this->settings(), $alice);
        $this->assertStatus(403);
    }

    public function testSettingsCanBeTriedBeforeBeingSaved(): void
    {
        $this->client->disableReboot();
        $admin = $this->admin();
        $directory = new class implements UserDirectoryInterface {
            public ?LdapConfig $probed = null;

            public function isEnabled(): bool
            {
                return false;
            }

            public function checkCredentials(string $dn, string $password): bool
            {
                return false;
            }

            public function fetchUsers(): iterable
            {
                return [];
            }

            public function probe(LdapConfig $config, int $limit = 5): array
            {
                $this->probed = $config;
                if ('wrong' === $config->bindPassword) {
                    throw new \RuntimeException('Invalid credentials');
                }

                return ['count' => 12, 'sample' => [new DirectoryUser('uid=jean,ou=people,dc=example,dc=org', 'jean@example.org', 'Jean', 'Dupont', false)]];
            }

            public function ping(LdapConfig $config): void
            {
            }
        };
        static::getContainer()->set(UserDirectoryInterface::class, $directory);

        $result = $this->api('POST', '/api/ldap/test', $this->settings(['attributes' => ['email' => 'userPrincipalName']]), $admin);
        $this->assertStatus(200);
        self::assertTrue($result['ok']);
        self::assertSame(12, $result['count']);
        self::assertSame('jean@example.org', $result['sample'][0]['email']);
        self::assertSame('userPrincipalName', $directory->probed->attributes['email']);
        self::assertSame('givenName', $directory->probed->attributes['firstName'], 'missing attributes fall back to the defaults');

        $failed = $this->api('POST', '/api/ldap/test', $this->settings(['bindPassword' => 'wrong']), $admin);
        self::assertFalse($failed['ok']);
        self::assertSame('Invalid credentials', $failed['message']);

        // Nothing was saved.
        self::assertSame('environment', $this->api('GET', '/api/ldap/config', authorization: $admin)['source']);
    }
}
