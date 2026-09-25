<?php

namespace App\Tests\Functional;

use App\Command\DemoSeedCommand;
use App\Entity\Application;
use App\Entity\AuthenticationServer;
use App\Entity\User;
use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application as ConsoleApplication;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

final class DemoSeedCommandTest extends WebTestCase
{
    use ApiTestTrait;

    private const DEMO_TOKEN = 'raa_demo_0000000000000000000000000000000000';

    private function seed(): CommandTester
    {
        $tester = new CommandTester((new ConsoleApplication(static::$kernel))->find('app:demo:seed'));
        $tester->execute([]);

        return $tester;
    }

    public function testSeedIsIdempotentAndUsable(): void
    {
        $this->seed()->assertCommandIsSuccessful();
        $this->seed()->assertCommandIsSuccessful();

        self::assertCount(\count(DemoSeedCommand::USERS), $this->em()->getRepository(User::class)->findAll());
        self::assertCount(1, $this->em()->getRepository(Application::class)->findAll());

        [$adminEmail, $adminPassword] = DemoSeedCommand::USERS[0];
        $login = $this->api('POST', '/api/auth/login', ['email' => $adminEmail, 'password' => $adminPassword]);
        $this->assertStatus(200);
        self::assertContains('ROLE_ADMIN', $this->api('GET', '/api/me', authorization: 'Bearer '.$login['token'])['roles']);

        $me = $this->api('GET', '/api/me', authorization: 'Bearer '.self::DEMO_TOKEN, headers: ['X-Impersonate-User' => 'alice@example.org']);
        $this->assertStatus(200);
        self::assertSame('alice@example.org', $me['user']['email']);
    }

    public function testDeclaresTheSingleSignOnProviderWhenConfigured(): void
    {
        $container = static::getContainer();
        $command = $this->command(true, 'http://localhost:3100', 'http://auth-api', 'demo-client', 'demo-secret', 'rocket-admins');
        $command(new SymfonyStyle(new ArrayInput([]), new NullOutput()));
        $command(new SymfonyStyle(new ArrayInput([]), new NullOutput()));

        $servers = $this->em()->getRepository(AuthenticationServer::class)->findAll();
        self::assertCount(1, $servers);
        self::assertSame('http://localhost:3100', $servers[0]->getUrl());
        self::assertSame('demo-client', $servers[0]->getClientId());
        self::assertSame('demo-secret', $container->get(\App\Security\SecretBox::class)->decrypt($servers[0]->getEncryptedClientSecret()));
        self::assertTrue($servers[0]->isLinkExistingAccounts());
    }

    public function testRefusesOutsideDemoMode(): void
    {
        $io = new SymfonyStyle(new ArrayInput([]), new NullOutput());
        self::assertSame(Command::FAILURE, ($this->command(false))($io));
        self::assertSame([], $this->em()->getRepository(User::class)->findAll());
    }

    private function command(bool $demoMode, string ...$sso): DemoSeedCommand
    {
        $container = static::getContainer();

        return new DemoSeedCommand(
            $container->get(\Doctrine\ORM\EntityManagerInterface::class),
            $container->get(\App\Repository\UserRepository::class),
            $container->get(\App\Repository\ApplicationRepository::class),
            $container->get(\App\Repository\AuthenticationServerRepository::class),
            $container->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class),
            $container->get('security.token_storage'),
            $container->get(\App\Security\SecretBox::class),
            [],
            $demoMode,
            self::DEMO_TOKEN,
            ...$sso,
        );
    }
}
