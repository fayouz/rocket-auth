<?php

namespace App\Tests\Functional;

use App\Entity\AuthenticationServer;
use App\Entity\User;
use App\Enum\AuthenticationServerType;
use App\Enum\UserSource;
use App\Oidc\Jwt;
use App\Tests\ApiTestTrait;
use App\Tests\Support\HttpMock;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OidcLoginTest extends WebTestCase
{
    use ApiTestTrait {
        setUp as private apiSetUp;
    }

    private const ISSUER = 'https://auth.example.org';
    private const INTERNAL = 'http://auth-api';
    private const VERIFIER = 'a-pkce-verifier-that-is-long-enough-0123456789abcdef';

    private \OpenSSLAsymmetricKey $key;
    /** @var array<string, mixed> */
    private array $tokenRequest = [];

    protected function setUp(): void
    {
        $this->apiSetUp();
        HttpMock::reset();
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        HttpMock::json(self::INTERNAL.'/.well-known/openid-configuration', [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => 'https://auth.example.org/authorize',
            'token_endpoint' => self::ISSUER.'/oauth/token',
            'userinfo_endpoint' => self::ISSUER.'/oauth/userinfo',
            'jwks_uri' => self::ISSUER.'/oauth/jwks',
            'end_session_endpoint' => 'https://auth.example.org/logout',
        ]);
        HttpMock::json(self::INTERNAL.'/oauth/jwks', ['keys' => [Jwt::publicJwk($this->key)]]);
    }

    public function testProvidersAreListedPublicly(): void
    {
        $this->createServer();
        $this->createServer(name: 'Disabled', enabled: false);

        $response = $this->api('GET', '/api/auth/providers');
        $this->assertStatus(200);
        self::assertCount(1, $response['providers']);
        self::assertSame('Rocket Auth', $response['providers'][0]['name']);
        self::assertSame('https://auth.example.org/authorize', $response['providers'][0]['authorizationEndpoint']);
        self::assertSame('rocket-auth', $response['providers'][0]['clientId']);
        self::assertSame('openid email profile groups', $response['providers'][0]['scope']);
    }

    public function testSignInCreatesTheAccountAndOpensASession(): void
    {
        $server = $this->createServer();
        $this->mockTokenEndpoint(['sub' => 'sub-alice', 'email' => 'Alice@Example.org', 'given_name' => 'Alice', 'family_name' => 'Martin', 'nonce' => 'n-1']);

        $response = $this->signIn($server, nonce: 'n-1');
        $this->assertStatus(200);

        // Server-to-server calls go through the internal URL, with the client secret and the PKCE verifier.
        self::assertSame('authorization_code', $this->tokenRequest['grant_type']);
        self::assertSame(self::VERIFIER, $this->tokenRequest['code_verifier']);
        self::assertSame('s3cret', $this->tokenRequest['client_secret']);
        self::assertSame('http://localhost:3000/auth/callback', $this->tokenRequest['redirect_uri']);

        $me = $this->api('GET', '/api/me', authorization: 'Bearer '.$response['token']);
        $this->assertStatus(200);
        self::assertSame('alice@example.org', $me['user']['email']);
        self::assertSame('oidc', $me['user']['source']);
        self::assertSame('Rocket Auth', $me['user']['authenticationServerName']);
        self::assertSame('Alice Martin', $me['user']['displayName']);

        // Next sign-in: same account, found by its subject.
        $this->mockTokenEndpoint(['sub' => 'sub-alice', 'email' => 'alice@example.org', 'nonce' => 'n-2']);
        $this->signIn($server, nonce: 'n-2');
        $this->assertStatus(200);
        self::assertSame(1, $this->em()->getRepository(User::class)->count([]));
    }

    public function testOidcAccountsCannotUseThePasswordLogin(): void
    {
        $server = $this->createServer();
        $this->mockTokenEndpoint(['sub' => 'sub-alice', 'email' => 'alice@example.org']);
        $this->signIn($server);
        $this->assertStatus(200);

        $this->api('POST', '/api/auth/login', ['email' => 'alice@example.org', 'password' => 'anything-at-all']);
        $this->assertStatus(401);
    }

    public function testTokensAreVerified(): void
    {
        $server = $this->createServer();

        $this->mockTokenEndpoint(['sub' => 'sub-alice', 'email' => 'alice@example.org', 'nonce' => 'other']);
        self::assertStringContainsString('nonce', $this->signIn($server, nonce: 'expected')['message']);
        $this->assertStatus(401);

        $this->mockTokenEndpoint(['sub' => 'sub-alice', 'email' => 'alice@example.org', 'aud' => 'another-client']);
        self::assertStringContainsString('another client', $this->signIn($server)['message']);

        $this->mockTokenEndpoint(['sub' => 'sub-alice', 'email' => 'alice@example.org', 'iss' => 'https://evil.example']);
        self::assertStringContainsString('another issuer', $this->signIn($server)['message']);

        $this->mockTokenEndpoint(['sub' => 'sub-alice', 'email' => 'alice@example.org', 'exp' => time() - 3600]);
        self::assertStringContainsString('expired', $this->signIn($server)['message']);

        // Signed by another key.
        $this->mockTokenEndpoint(['sub' => 'sub-alice', 'email' => 'alice@example.org'], openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]));
        self::assertStringContainsString('signature', $this->signIn($server)['message']);

        self::assertSame(0, $this->em()->getRepository(User::class)->count([]));
    }

    public function testExistingAccountsAreOnlyLinkedWhenTheProviderIsTrusted(): void
    {
        $this->createUser('alice@example.org');
        $server = $this->createServer();
        $this->mockTokenEndpoint(['sub' => 'sub-alice', 'email' => 'alice@example.org']);
        self::assertStringContainsString('already uses this email', $this->signIn($server)['message']);
        $this->assertStatus(401);

        $server->setLinkExistingAccounts(true);
        $this->em()->flush();
        $response = $this->signIn($server);
        $this->assertStatus(200);
        $me = $this->api('GET', '/api/me', authorization: 'Bearer '.$response['token']);
        // The local account keeps its password; it is now reachable through the provider too.
        self::assertSame('local', $me['user']['source']);
    }

    public function testAdminRoleFollowsTheConfiguredGroup(): void
    {
        $server = $this->createServer(adminGroup: 'rocket-admins');
        $this->mockTokenEndpoint(['sub' => 'sub-marie', 'email' => 'marie@example.org', 'groups' => ['staff', 'rocket-admins']]);
        $token = $this->signIn($server)['token'];
        self::assertContains('ROLE_ADMIN', $this->api('GET', '/api/me', authorization: 'Bearer '.$token)['roles']);

        $this->mockTokenEndpoint(['sub' => 'sub-marie', 'email' => 'marie@example.org', 'groups' => ['staff']]);
        $token = $this->signIn($server)['token'];
        self::assertNotContains('ROLE_ADMIN', $this->api('GET', '/api/me', authorization: 'Bearer '.$token)['roles']);
    }

    public function testDisabledAccountsAreRefused(): void
    {
        $server = $this->createServer();
        $this->mockTokenEndpoint(['sub' => 'sub-alice', 'email' => 'alice@example.org']);
        $this->signIn($server);
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'alice@example.org']);
        $user->setEnabled(false);
        $this->em()->flush();

        self::assertStringContainsString('disabled', $this->signIn($server)['message']);
        $this->assertStatus(401);
    }

    public function testAdminsConfigureProvidersWithAnEncryptedSecret(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_ADMIN']);
        $auth = 'Bearer '.$this->jwtFor($admin);

        $test = $this->api('POST', '/api/authentication_servers/oidc/test', ['url' => self::ISSUER, 'internalUrl' => self::INTERNAL], $auth);
        $this->assertStatus(200);
        self::assertTrue($test['ok']);
        self::assertSame('https://auth.example.org/authorize', $test['authorizationEndpoint']);

        $failed = $this->api('POST', '/api/authentication_servers/oidc/test', ['url' => 'https://other.example.org'], $auth);
        self::assertFalse($failed['ok']);

        $this->api('POST', '/api/authentication_servers', ['name' => 'Bad', 'type' => 'oidc', 'url' => 'not a url', 'scopes' => 'email'], $auth);
        $this->assertStatus(422);

        $created = $this->api('POST', '/api/authentication_servers', [
            'name' => 'Rocket Auth', 'type' => 'oidc', 'enabled' => true, 'url' => self::ISSUER, 'internalUrl' => self::INTERNAL.'/',
            'clientId' => 'rocket-auth', 'clientSecret' => 'top-secret', 'scopes' => 'openid  email profile',
        ], $auth);
        $this->assertStatus(201);
        self::assertArrayNotHasKey('clientSecret', $created);
        self::assertTrue($created['hasClientSecret']);
        self::assertSame(self::INTERNAL, $created['internalUrl']);
        self::assertSame('openid email profile', $created['scopes']);

        $server = $this->em()->getRepository(AuthenticationServer::class)->find($created['id']);
        self::assertStringStartsWith('v1:', $server->getEncryptedClientSecret());
        $encrypted = $server->getEncryptedClientSecret();

        // An empty secret keeps the current one.
        $this->api('PATCH', '/api/authentication_servers/'.$created['id'], ['name' => 'SSO', 'clientSecret' => ''], $auth);
        $this->assertStatus(200);
        $this->em()->clear();
        self::assertSame($encrypted, $this->em()->getRepository(AuthenticationServer::class)->find($created['id'])->getEncryptedClientSecret());

        $user = $this->createUser('alice@example.org');
        $this->api('POST', '/api/authentication_servers/oidc/test', ['url' => self::ISSUER], 'Bearer '.$this->jwtFor($user));
        $this->assertStatus(403);
    }

    private function createServer(string $name = 'Rocket Auth', bool $enabled = true, string $adminGroup = ''): AuthenticationServer
    {
        $server = (new AuthenticationServer())
            ->setName($name)
            ->setType(AuthenticationServerType::Oidc)
            ->setEnabled($enabled)
            ->setUrl(self::ISSUER)
            ->setInternalUrl(self::INTERNAL)
            ->setClientId('rocket-auth')
            ->setEncryptedClientSecret(static::getContainer()->get(\App\Security\SecretBox::class)->encrypt('s3cret'))
            ->setScopes('openid email profile groups')
            ->setAdminGroupDn($adminGroup);
        $this->em()->persist($server);
        $this->em()->flush();

        return $server;
    }

    /** @param array<string, mixed> $claims */
    private function mockTokenEndpoint(array $claims, ?\OpenSSLAsymmetricKey $key = null): void
    {
        $idToken = Jwt::sign($claims + ['iss' => self::ISSUER, 'aud' => 'rocket-auth', 'iat' => time(), 'exp' => time() + 300], $key ?? $this->key, Jwt::publicJwk($this->key)['kid']);
        HttpMock::on(self::INTERNAL.'/oauth/token', function (string $method, string $url, array $options) use ($idToken) {
            parse_str((string) $options['body'], $this->tokenRequest);

            return new MockResponse(json_encode(['access_token' => 'at', 'token_type' => 'Bearer', 'id_token' => $idToken], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });
        HttpMock::json(self::INTERNAL.'/oauth/userinfo', ['sub' => $claims['sub']]);
    }

    /** @return array<string, mixed> */
    private function signIn(AuthenticationServer $server, ?string $nonce = null): array
    {
        return $this->api('POST', '/api/auth/oidc/callback', [
            'provider' => (string) $server->getId(),
            'code' => 'the-code',
            'codeVerifier' => self::VERIFIER,
            'redirectUri' => 'http://localhost:3000/auth/callback',
            'nonce' => $nonce,
        ]) ?? [];
    }

    public function testProvidersAreHealthChecked(): void
    {
        $server = $this->createServer();
        $this->createServer(name: 'Broken')->setInternalUrl('http://unreachable');
        $this->em()->flush();
        $admin = $this->createUser('admin@example.org', ['ROLE_ADMIN']);

        $this->api('POST', '/api/health/check', authorization: 'Bearer '.$this->jwtFor($admin));
        $this->assertStatus(200);
        $dashboard = $this->api('GET', '/api/dashboard', authorization: 'Bearer '.$this->jwtFor($admin));
        $sso = array_values(array_filter($dashboard['health']['services'], static fn (array $s) => 'sso' === $s['id']))[0];
        self::assertSame('degraded', $sso['status']);
        self::assertSame(2, $sso['total']);
        self::assertSame(1, $sso['failing']);
        self::assertSame('operational', array_values(array_filter($sso['items'], static fn (array $i) => $i['id'] === (string) $server->getId()))[0]['status']);
    }
}
