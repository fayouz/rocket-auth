<?php

namespace App\Tests;

use Rocket\Core\Entity\Application;
use Rocket\Core\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

trait ApiTestTrait
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param list<string> $roles */
    protected function createUser(string $email, array $roles = [], string $password = 'correct-horse-battery', bool $enabled = true): User
    {
        $user = (new User())->setEmail($email)->setRoles($roles)->setEnabled($enabled);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /** @return array{0: Application, 1: string} the application and its secret */
    protected function createApplication(bool $canImpersonate = true, string $name = 'Partner CRM'): array
    {
        $application = (new Application())->setName($name)->setCanImpersonate($canImpersonate);
        $token = $application->rotateToken();
        $this->em()->persist($application);
        $this->em()->flush();

        return [$application, $token];
    }

    protected function jwtFor(User $user): string
    {
        return static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<mixed>|null
     */
    protected function api(string $method, string $uri, ?array $json = null, ?string $authorization = null, array $headers = []): ?array
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if (null !== $authorization) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        if (null !== $json) {
            $server['CONTENT_TYPE'] = 'PATCH' === $method ? 'application/merge-patch+json' : 'application/json';
        }

        $this->client->request($method, $uri, server: $server, content: null === $json ? null : json_encode($json, \JSON_THROW_ON_ERROR));
        $content = $this->client->getResponse()->getContent();

        return '' === $content || false === $content ? null : json_decode($content, true);
    }

    protected function assertStatus(int $expected): void
    {
        self::assertSame($expected, $this->client->getResponse()->getStatusCode(), mb_substr((string) $this->client->getResponse()->getContent(), 0, 600));
    }
}
