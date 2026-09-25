<?php

namespace App\Tests\Functional;

use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthenticationTest extends WebTestCase
{
    use ApiTestTrait;

    public function testLocalUserLogsInAndReadsProfile(): void
    {
        $this->createUser('alice@example.org');

        $response = $this->api('POST', '/api/auth/login', ['email' => 'Alice@Example.org', 'password' => 'correct-horse-battery']);
        $this->assertStatus(200);
        self::assertArrayHasKey('token', $response);

        $me = $this->api('GET', '/api/me', authorization: 'Bearer '.$response['token']);
        $this->assertStatus(200);
        self::assertSame('alice@example.org', $me['user']['email']);
        self::assertNull($me['application']);
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->createUser('alice@example.org');

        $this->api('POST', '/api/auth/login', ['email' => 'alice@example.org', 'password' => 'nope']);
        $this->assertStatus(401);
    }

    public function testDisabledUserCannotLogIn(): void
    {
        $this->createUser('bob@example.org', enabled: false);

        $this->api('POST', '/api/auth/login', ['email' => 'bob@example.org', 'password' => 'correct-horse-battery']);
        $this->assertStatus(401);
    }

    public function testApiRequiresAuthentication(): void
    {
        $this->api('GET', '/api/dashboard');
        $this->assertStatus(401);
    }

    public function testOnlyAdminsManageUsers(): void
    {
        $user = $this->createUser('alice@example.org');
        $admin = $this->createUser('admin@example.org', ['ROLE_ADMIN']);

        $this->api('GET', '/api/users', authorization: 'Bearer '.$this->jwtFor($user));
        $this->assertStatus(403);

        $created = $this->api('POST', '/api/users', ['email' => 'new@example.org', 'plainPassword' => 'a-long-enough-password'], 'Bearer '.$this->jwtFor($admin));
        $this->assertStatus(201);
        self::assertSame('local', $created['source']);
        self::assertSame('admin@example.org', $created['createdBy']);
        self::assertArrayNotHasKey('password', $created);
        self::assertArrayNotHasKey('plainPassword', $created);
    }
}
