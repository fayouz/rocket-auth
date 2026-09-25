<?php

namespace App\Tests\Functional;

use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DashboardTest extends WebTestCase
{
    use ApiTestTrait;

    public function testUsersSeeTheirOwnScopeWithoutPlatformDetails(): void
    {
        $alice = $this->createUser('alice@example.org');

        $stats = $this->api('GET', '/api/dashboard', authorization: 'Bearer '.$this->jwtFor($alice));
        $this->assertStatus(200);

        self::assertSame('user', $stats['scope']);
        self::assertSame(30, $stats['days']);
        self::assertCount(30, $stats['daily']);
        self::assertSame(date('Y-m-d'), end($stats['daily'])['date']);
        self::assertArrayNotHasKey('users', $stats);
        self::assertArrayNotHasKey('applications', $stats);
        self::assertNotContains('users', array_column($stats['kpis'], 'id'));
        self::assertSame(['status'], array_keys($stats['health']));
    }

    public function testAdminsSeeThePlatformAndServiceDetails(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_ADMIN']);
        $this->createUser('alice@example.org');
        $this->createApplication();

        $stats = $this->api('GET', '/api/dashboard', authorization: 'Bearer '.$this->jwtFor($admin));
        $this->assertStatus(200);

        self::assertSame('platform', $stats['scope']);
        self::assertSame(['total' => 2, 'enabled' => 2, 'local' => 2, 'ldap' => 0, 'oidc' => 0], $stats['users']);
        self::assertContains('users', array_column($stats['kpis'], 'id'));
        self::assertSame('Partner CRM', $stats['applications'][0]['name']);
        self::assertContains('application.created', array_column($stats['activity'], 'type'));
        self::assertContains('user.created', array_column($stats['activity'], 'type'));

        $services = array_column($stats['health']['services'], null, 'id');
        self::assertSame(['database', 'queue', 'ldap', 'sso', 'storage'], array_keys($services));
        self::assertSame('operational', $services['database']['status']);
        self::assertSame('disabled', $services['ldap']['status']);
        self::assertSame('operational', $services['queue']['status']);
        self::assertContains($services['storage']['status'], ['operational', 'degraded']);
    }

    public function testApplicationsCannotReadTheDashboard(): void
    {
        $this->createUser('alice@example.org');
        [, $token] = $this->createApplication();

        $this->api('GET', '/api/dashboard', authorization: 'Bearer '.$token);
        $this->assertStatus(403);
    }
}
