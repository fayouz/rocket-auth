<?php

namespace App\Tests\Functional;

use App\Tests\ApiTestTrait;
use App\Tests\Support\HttpMock;
use App\Update\AppVersion;
use App\Command\UpdateRunCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;

final class SystemUpdateTest extends WebTestCase
{
    use ApiTestTrait {
        setUp as private apiSetUp;
    }

    private const GITHUB = 'https://api.github.com/repos/acme/rocket-auth/';

    protected function setUp(): void
    {
        $this->apiSetUp();
        HttpMock::reset();
        static::getContainer()->get(CacheInterface::class)->delete('app.update.latest_release.v2');
    }

    private function admin(): string
    {
        return 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
    }

    public function testEveryUserSeesTheVersionButOnlyAdminsTheUpdates(): void
    {
        $user = 'Bearer '.$this->jwtFor($this->createUser('user@example.org'));

        self::assertSame(['version' => '0.6.0+2 (abc1234)', 'release' => '0.6.0'], $this->api('GET', '/api/system/version', authorization: $user));
        $this->api('GET', '/api/system/update', authorization: $user);
        $this->assertStatus(403);
        $this->api('POST', '/api/system/update', authorization: $user);
        $this->assertStatus(403);
        $this->api('GET', '/api/system/version');
        $this->assertStatus(401);
    }

    public function testFindsTheLatestReleaseAndCachesIt(): void
    {
        HttpMock::json(self::GITHUB.'releases', [
            ['tag_name' => 'v0.6.0', 'name' => 'v0.6.0', 'html_url' => 'https://github.com/acme/rocket-auth/releases/tag/v0.6.0', 'draft' => false, 'prerelease' => false, 'published_at' => '2026-09-24T10:00:00Z', 'body' => 'Tableau de bord'],
            ['tag_name' => 'v0.8.0-rc.1', 'name' => 'RC', 'html_url' => 'x', 'draft' => false, 'prerelease' => true],
            ['tag_name' => 'v0.7.0', 'name' => '0.7.0 — Boîtes d’envoi', 'html_url' => 'https://github.com/acme/rocket-auth/releases/tag/v0.7.0', 'draft' => false, 'prerelease' => false, 'published_at' => '2026-09-25T10:00:00Z', 'body' => '- Boîtes d’envoi'],
        ]);

        $status = $this->api('GET', '/api/system/update', authorization: $admin = $this->admin());
        $this->assertStatus(200);
        self::assertSame('0.6.0+2 (abc1234)', $status['current']['version']);
        self::assertSame('0.7.0', $status['latest']['version']);
        self::assertSame('0.7.0 — Boîtes d’envoi', $status['latest']['name']);
        self::assertSame('- Boîtes d’envoi', $status['latest']['notes']);
        self::assertTrue($status['updateAvailable']);
        self::assertTrue($status['methods']['docker']['configured']);
        // UPDATER_TOKEN set, no choice saved: Docker.
        self::assertSame(['docker', 'environment'], [$status['method'], $status['methodSource']]);
        self::assertNull($status['error']);
        self::assertContains('User-Agent: Rocket-Mailer', HttpMock::$requests[0]['headers']);

        // Cached: GitHub is asked once an hour, unless the administrator asks for a new check.
        $this->api('GET', '/api/system/update', authorization: $admin);
        self::assertCount(1, HttpMock::$requests);
        $this->api('GET', '/api/system/update?refresh=1', authorization: $admin);
        self::assertCount(2, HttpMock::$requests);
    }

    public function testFallsBackToTagsAndReportsGithubErrors(): void
    {
        HttpMock::json(self::GITHUB.'releases', []);
        HttpMock::json(self::GITHUB.'tags', [['name' => 'v0.5.0'], ['name' => 'v0.6.0'], ['name' => 'nightly'], ['name' => 'v0.7.0-beta']]);

        $status = $this->api('GET', '/api/system/update', authorization: $admin = $this->admin());
        self::assertSame('0.6.0', $status['latest']['version']);
        self::assertSame('https://github.com/acme/rocket-auth/tree/v0.6.0', $status['latest']['url']);
        // Two commits after 0.6.0: up to date.
        self::assertFalse($status['updateAvailable']);

        HttpMock::json(self::GITHUB.'releases', ['message' => 'API rate limit exceeded'], 403);
        $status = $this->api('GET', '/api/system/update?refresh=1', authorization: $admin);
        $this->assertStatus(200);
        self::assertNull($status['latest']);
        self::assertStringContainsString('réessayez dans une heure', $status['error']);
    }

    public function testStartsTheUpdateThroughTheUpdater(): void
    {
        HttpMock::on('http://updater.test:8080/v1/update', static fn () => new MockResponse('', ['http_code' => 202]));

        HttpMock::json(self::GITHUB.'releases', []);
        HttpMock::json(self::GITHUB.'tags', []);
        $response = $this->api('POST', '/api/system/update', authorization: $admin = $this->admin());
        $this->assertStatus(202);
        self::assertTrue($response['started']);
        self::assertSame(['docker', 'started', '0.6.0+2 (abc1234)'], [$response['run']['method'], $response['run']['status'], $response['run']['fromVersion']]);
        $request = end(HttpMock::$requests);
        self::assertSame(['POST', 'http://updater.test:8080/v1/update?async=true'], [$request['method'], $request['url']]);
        self::assertContains('Authorization: Bearer updater-test-token', $request['headers']);

        // One update at a time.
        $response = $this->api('POST', '/api/system/update', authorization: $admin);
        $this->assertStatus(422);
        self::assertStringContainsString('déjà en cours', $response['detail'] ?? '');
        self::assertCount(1, array_filter(HttpMock::$requests, static fn ($r) => str_contains($r['url'], 'updater.test')));

        // Watchtower refuses (e.g. already updating): nothing is recorded.
        $this->em()->createQuery('DELETE FROM App\Entity\UpdateRun')->execute();
        HttpMock::on('http://updater.test:8080/v1/update', static fn () => new MockResponse('', ['http_code' => 429]));
        $response = $this->api('POST', '/api/system/update', authorization: $admin);
        $this->assertStatus(422);
        self::assertStringContainsString('déjà en cours', $response['detail'] ?? '');
        self::assertNull($this->api('GET', '/api/system/update', authorization: $admin)['run']);
    }

    public function testScriptMethodRunsThroughTheScheduledTask(): void
    {
        HttpMock::json(self::GITHUB.'releases', [
            ['tag_name' => 'v0.7.0', 'name' => 'v0.7.0', 'html_url' => 'x', 'draft' => false, 'prerelease' => false],
        ]);
        $admin = $this->admin();

        // Chosen in the administration, over the default (Docker here).
        self::assertSame(['method' => 'script', 'methodSource' => 'database'], $this->api('PUT', '/api/system/update/method', ['method' => 'script'], $admin));
        $this->api('PUT', '/api/system/update/method', ['method' => 'ftp'], $admin);
        $this->assertStatus(422);

        $status = $this->api('GET', '/api/system/update', authorization: $admin);
        self::assertTrue($status['methods']['script']['installed']);
        self::assertFalse($status['methods']['script']['schedulerAlive']);

        $response = $this->api('POST', '/api/system/update', authorization: $admin);
        $this->assertStatus(202);
        self::assertSame(['script', 'requested', 'v0.7.0'], [$response['run']['method'], $response['run']['status'], $response['run']['target']]);
        self::assertEmpty(array_filter(HttpMock::$requests, static fn ($r) => str_contains($r['url'], 'updater.test')));

        // The scheduled task runs the script with the version to install, and keeps its output.
        $tester = $this->runScheduledTask();
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('==> Installing v0.7.0', $tester->getDisplay());

        $status = $this->api('GET', '/api/system/update', authorization: $admin);
        self::assertTrue($status['methods']['script']['schedulerAlive']);
        self::assertSame('succeeded', $status['run']['status']);
        self::assertStringContainsString("restart: sudo systemctl restart rocket-auth-front\n==> Done", $status['run']['log']);
        self::assertSame('admin@example.org', $status['run']['requestedBy']);

        // Nothing waiting: the task only records its heartbeat.
        self::assertSame('', trim($this->runScheduledTask()->getDisplay()));

        // Back to the default method.
        self::assertSame(['method' => 'docker', 'methodSource' => 'environment'], $this->api('PUT', '/api/system/update/method', ['method' => null], $admin));
    }

    public function testFailedOrCancelledScriptUpdates(): void
    {
        HttpMock::json(self::GITHUB.'releases', [
            ['tag_name' => 'v0.9.9', 'name' => 'v0.9.9', 'html_url' => 'x', 'draft' => false, 'prerelease' => false],
        ]);
        $admin = $this->admin();
        $this->api('PUT', '/api/system/update/method', ['method' => 'script'], $admin);

        $this->api('POST', '/api/system/update', authorization: $admin);
        self::assertSame('cancelled', $this->api('POST', '/api/system/update/cancel', authorization: $admin)['run']['status']);
        $this->api('POST', '/api/system/update/cancel', authorization: $admin);
        $this->assertStatus(422);
        self::assertSame('', trim($this->runScheduledTask()->getDisplay()));

        $this->api('POST', '/api/system/update', authorization: $admin);
        self::assertSame(1, $this->runScheduledTask()->getStatusCode());
        $run = $this->api('GET', '/api/system/update', authorization: $admin)['run'];
        self::assertSame('failed', $run['status']);
        self::assertStringContainsString('composer install failed', $run['log']);
        self::assertStringContainsString('code 3', $run['log']);

        // Manual method: no button.
        $this->api('PUT', '/api/system/update/method', ['method' => 'manual'], $admin);
        $response = $this->api('POST', '/api/system/update', authorization: $admin);
        $this->assertStatus(422);
        self::assertStringContainsString('manuelle', $response['detail']);
    }

    private function runScheduledTask(): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:update:run'));
        $tester->execute([]);
        $this->em()->clear();

        return $tester;
    }

    public function testParsesBuildVersions(): void
    {
        self::assertSame('0.7.0', AppVersion::parse('v0.7.0')->label());
        self::assertFalse(AppVersion::parse('v0.7.0')->isOlderThan('0.7.0'));
        self::assertTrue(AppVersion::parse('v0.7.0')->isOlderThan('0.10.0'));
        self::assertSame('0.7.0-rc.1', AppVersion::parse('v0.7.0-rc.1')->release);
        self::assertSame(3, AppVersion::parse('0.7.0-rc.1-3-gdeadbeef')->ahead);
        self::assertNull(AppVersion::parse('develop')->isOlderThan('1.0.0'));
        self::assertSame('dev', AppVersion::parse('')->label());
    }
}
