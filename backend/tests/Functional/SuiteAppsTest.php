<?php

namespace App\Tests\Functional;

use App\Entity\OAuthClient;
use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SuiteAppsTest extends WebTestCase
{
    use ApiTestTrait;

    public function testTheSwitcherListsTheApplicationsWithAnAddress(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_ADMIN']);
        $jwt = 'Bearer '.$this->jwtFor($admin);
        foreach ([
            ['rocket-print', 'Rocket Print', 'https://print.example.org', 'i-lucide-printer', true],
            ['rocket-cloud', 'Rocket Cloud', 'https://cloud.example.org', null, false],
            ['crm', 'CRM', null, null, true],
        ] as [$clientId, $name, $url, $icon, $enabled]) {
            $this->api('POST', '/api/oauth/clients', [
                'name' => $name, 'clientId' => $clientId, 'redirectUris' => ['https://app.example.org/auth/callback'],
                'homeUrl' => $url, 'icon' => $icon, 'enabled' => $enabled,
            ], $jwt);
            $this->assertStatus(201);
        }
        $this->api('POST', '/api/oauth/clients', ['name' => 'Bad', 'redirectUris' => ['https://a.example.org/cb'], 'homeUrl' => 'javascript:alert(1)'], $jwt);
        $this->assertStatus(422);

        // Public: every application of the suite reads it, before anyone signs in.
        $apps = $this->api('GET', '/api/suite/apps');
        $this->assertStatus(200);
        self::assertSame([['id' => 'print', 'name' => 'Rocket Print', 'url' => 'https://print.example.org', 'icon' => 'i-lucide-printer', 'description' => null]], $apps['apps']);
        self::assertSame(1, $this->em()->getRepository(OAuthClient::class)->count(['homeUrl' => null]));
        self::assertSame('http://localhost:3100', $apps['account']);
    }
}
