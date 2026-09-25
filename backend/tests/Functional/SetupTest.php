<?php

namespace App\Tests\Functional;

use App\Setup\FirstRunSetup;
use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SetupTest extends WebTestCase
{
    use ApiTestTrait;

    public function testFirstRunCreatesTheAdministratorOnce(): void
    {
        self::assertSame(['required' => true, 'tokenRequired' => false], $this->api('GET', '/api/setup'));

        $this->api('POST', '/api/setup', ['email' => 'admin@example.org', 'password' => 'short']);
        $this->assertStatus(422);

        $created = $this->api('POST', '/api/setup', [
            'email' => 'Admin@Example.org',
            'password' => 'a-long-enough-password',
            'firstName' => 'Ada',
            'lastName' => 'Admin',
        ]);
        $this->assertStatus(201);
        self::assertSame('admin@example.org', $created['email']);

        // Logged in right away, as an administrator.
        $me = $this->api('GET', '/api/me', authorization: 'Bearer '.$created['token']);
        $this->assertStatus(200);
        self::assertContains('ROLE_ADMIN', $me['roles']);
        self::assertSame('Ada Admin', $me['user']['displayName']);

        // And with the password.
        $login = $this->api('POST', '/api/auth/login', ['email' => 'admin@example.org', 'password' => 'a-long-enough-password']);
        $this->assertStatus(200);
        self::assertArrayHasKey('token', $login);

        // Never again.
        self::assertSame(['required' => false, 'tokenRequired' => false], $this->api('GET', '/api/setup'));
        $this->api('POST', '/api/setup', ['email' => 'intruder@example.org', 'password' => 'another-long-password']);
        $this->assertStatus(409);
    }

    public function testNotAvailableOnceUsersExist(): void
    {
        $this->createUser('alice@example.org');

        self::assertFalse($this->api('GET', '/api/setup')['required']);
        $this->api('POST', '/api/setup', ['email' => 'intruder@example.org', 'password' => 'another-long-password']);
        $this->assertStatus(409);
    }

    public function testSetupTokenIsRequiredWhenConfigured(): void
    {
        // Keep the overridden service across requests.
        $this->client->disableReboot();
        $container = static::getContainer();
        $container->set(FirstRunSetup::class, new FirstRunSetup(
            $this->em(),
            $container->get('security.user_password_hasher'),
            's3cret-setup-token',
        ));

        self::assertSame(['required' => true, 'tokenRequired' => true], $this->api('GET', '/api/setup'));
        $this->api('POST', '/api/setup', ['email' => 'admin@example.org', 'password' => 'a-long-enough-password', 'setupToken' => 'wrong']);
        $this->assertStatus(403);
        $this->api('POST', '/api/setup', ['email' => 'admin@example.org', 'password' => 'a-long-enough-password', 'setupToken' => 's3cret-setup-token']);
        $this->assertStatus(201);
    }
}
