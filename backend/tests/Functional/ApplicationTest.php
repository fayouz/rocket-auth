<?php

namespace App\Tests\Functional;

use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApplicationTest extends WebTestCase
{
    use ApiTestTrait;

    public function testSecretIsOnlyReturnedAtCreation(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_ADMIN']);
        $jwt = 'Bearer '.$this->jwtFor($admin);

        $created = $this->api('POST', '/api/applications', ['name' => 'CRM', 'canImpersonate' => true], $jwt);
        $this->assertStatus(201);
        self::assertStringStartsWith('raa_', $created['plainToken']);
        self::assertTrue($created['canImpersonate']);

        $fetched = $this->api('GET', '/api/applications/'.$created['id'], authorization: $jwt);
        $this->assertStatus(200);
        self::assertArrayNotHasKey('plainToken', $fetched);
        self::assertSame(substr($created['plainToken'], 0, 10), $fetched['tokenHint']);

        $me = $this->api('GET', '/api/me', authorization: 'Bearer '.$created['plainToken']);
        $this->assertStatus(200);
        self::assertSame('CRM', $me['application']['name']);
        self::assertNull($me['user']);
    }

    public function testRejectsBlankNames(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_ADMIN']);

        $this->api('POST', '/api/applications', ['name' => ''], 'Bearer '.$this->jwtFor($admin));
        $this->assertStatus(422);
    }

    public function testRegeneratedTokenRevokesThePreviousOne(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_ADMIN']);
        [$application, $token] = $this->createApplication();

        $new = $this->api('POST', '/api/applications/'.$application->getId().'/regenerate-token', authorization: 'Bearer '.$this->jwtFor($admin));
        $this->assertStatus(200);

        $this->api('GET', '/api/me', authorization: 'Bearer '.$token);
        $this->assertStatus(401);
        $this->api('GET', '/api/me', authorization: 'Bearer '.$new['token']);
        $this->assertStatus(200);
    }

    public function testApplicationWithoutImpersonationCannotReachResources(): void
    {
        [, $token] = $this->createApplication();

        $this->api('GET', '/api/dashboard', authorization: 'Bearer '.$token);
        $this->assertStatus(403);
    }

    public function testImpersonationRequiresPermission(): void
    {
        $this->createUser('alice@example.org');
        [, $token] = $this->createApplication(canImpersonate: false);

        $this->api('GET', '/api/me', authorization: 'Bearer '.$token, headers: ['X-Impersonate-User' => 'alice@example.org']);
        $this->assertStatus(401);
    }

    public function testDisabledApplicationIsRejected(): void
    {
        [$application, $token] = $this->createApplication();
        $application->setEnabled(false);
        $this->em()->flush();

        $this->api('GET', '/api/me', authorization: 'Bearer '.$token);
        $this->assertStatus(401);
    }

    public function testApplicationActsAsUser(): void
    {
        $this->createUser('alice@example.org');
        [, $token] = $this->createApplication();

        $me = $this->api('GET', '/api/me', authorization: 'Bearer '.$token, headers: ['X-Impersonate-User' => 'alice@example.org']);
        $this->assertStatus(200);
        self::assertSame('alice@example.org', $me['user']['email']);
        self::assertSame('Partner CRM', $me['application']['name']);
    }

    public function testImpersonatingAnAdminNeverGrantsAdminRole(): void
    {
        $this->createUser('admin@example.org', ['ROLE_ADMIN']);
        [, $token] = $this->createApplication();
        $headers = ['X-Impersonate-User' => 'admin@example.org'];

        $me = $this->api('GET', '/api/me', authorization: 'Bearer '.$token, headers: $headers);
        self::assertNotContains('ROLE_ADMIN', $me['roles']);

        $this->api('GET', '/api/users', authorization: 'Bearer '.$token, headers: $headers);
        $this->assertStatus(403);
    }
}
