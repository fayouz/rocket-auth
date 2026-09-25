<?php

namespace App\Tests\Functional;

use App\Entity\OAuthClient;
use App\Entity\User;
use App\Oidc\Jwt;
use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The OpenID Connect provider, driven the way an application and the interface drive it. */
final class OAuthProviderTest extends WebTestCase
{
    use ApiTestTrait;

    private const REDIRECT = 'https://crm.example.org/auth/callback';
    private const SECRET = 'a-demo-client-secret-long-enough';
    private const VERIFIER = 'the-pkce-code-verifier-of-this-test-0123456789';

    private function client(bool $trusted = false, bool $confidential = true, array $grants = ['authorization_code', 'refresh_token']): OAuthClient
    {
        $client = (new OAuthClient())->setName('Démo CRM')->setClientId('crm')->setRedirectUris([self::REDIRECT])
            ->setPostLogoutRedirectUris(['https://crm.example.org/'])->setTrusted($trusted)->setConfidential($confidential)->setGrantTypes($grants);
        if ($confidential) {
            $client->useSecret(self::SECRET);
        }
        $this->em()->persist($client);
        $this->em()->flush();

        return $client;
    }

    /** @return array<string, string> */
    private function params(array $overrides = []): array
    {
        return $overrides + [
            'response_type' => 'code',
            'client_id' => 'crm',
            'redirect_uri' => self::REDIRECT,
            'scope' => 'openid email profile groups',
            'state' => 'st4te',
            'nonce' => 'n0nce',
            'code_challenge' => Jwt::pkceChallenge(self::VERIFIER),
            'code_challenge_method' => 'S256',
        ];
    }

    /** Signs the user in through the interface endpoints and returns the authorization code. */
    private function authorize(User $user, array $params = []): string
    {
        $jwt = 'Bearer '.$this->jwtFor($user);
        $response = $this->api('POST', '/api/oauth/authorize', ['params' => $this->params($params), 'approve' => true], $jwt);
        $this->assertStatus(200);
        parse_str((string) parse_url($response['redirectUrl'], \PHP_URL_QUERY), $query);
        self::assertStringStartsWith(self::REDIRECT.'?', $response['redirectUrl']);
        self::assertSame('st4te', $query['state']);
        self::assertSame('http://localhost:8100', $query['iss']);

        return $query['code'];
    }

    /** @return array<string, mixed> */
    private function token(array $body, bool $basic = true): array
    {
        $server = [];
        if ($basic) {
            $server = ['PHP_AUTH_USER' => 'crm', 'PHP_AUTH_PW' => self::SECRET];
        }
        $this->client->request('POST', '/oauth/token', $body, server: $server);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    public function testDiscoveryAndKeys(): void
    {
        $this->client->request('GET', '/.well-known/openid-configuration');
        $this->assertStatus(200);
        $discovery = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('http://localhost:8100', $discovery['issuer']);
        self::assertSame('http://localhost:8100/oauth/token', $discovery['token_endpoint']);
        self::assertSame(['S256'], $discovery['code_challenge_methods_supported']);

        $this->client->request('GET', '/oauth/jwks');
        $keys = json_decode((string) $this->client->getResponse()->getContent(), true)['keys'];
        self::assertSame('RS256', $keys[0]['alg']);

        // The browser is sent to the interface, with the same parameters.
        $this->client->request('GET', '/oauth/authorize?client_id=crm&scope=openid');
        self::assertResponseRedirects('http://localhost:3100/authorize?client_id=crm&scope=openid');
    }

    public function testAuthorizationCodeFlowWithConsentAndPkce(): void
    {
        $this->client();
        $alice = $this->createUser('alice@example.org')->setFirstName('Alice')->setLastName('Durand')->setGroups(['sales']);
        $this->em()->flush();
        $jwt = 'Bearer '.$this->jwtFor($alice);

        // First time: the interface asks the user.
        $check = $this->api('GET', '/api/oauth/authorize?'.http_build_query($this->params()), authorization: $jwt);
        $this->assertStatus(200);
        self::assertTrue($check['consentRequired']);
        self::assertSame('Démo CRM', $check['client']['name']);
        self::assertSame(['openid', 'email', 'profile', 'groups'], array_column($check['scopes'], 'name'));

        $code = $this->authorize($alice);
        $tokens = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER]);
        $this->assertStatus(200);
        self::assertSame('Bearer', $tokens['token_type']);
        self::assertStringStartsWith('rar_', $tokens['refresh_token']);

        // The ID token, checked the way an application does (JWKS).
        $this->client->request('GET', '/oauth/jwks');
        $keys = json_decode((string) $this->client->getResponse()->getContent(), true)['keys'];
        $claims = Jwt::verify($tokens['id_token'], $keys);
        self::assertSame('http://localhost:8100', $claims['iss']);
        self::assertSame('crm', $claims['aud']);
        self::assertSame('n0nce', $claims['nonce']);
        self::assertSame((string) $alice->getId(), $claims['sub']);
        self::assertSame('alice@example.org', $claims['email']);
        self::assertSame('Alice', $claims['given_name']);
        self::assertSame(['sales'], $claims['groups']);

        $this->client->request('GET', '/oauth/userinfo', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['access_token']]);
        $this->assertStatus(200);
        $userinfo = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Alice Durand', $userinfo['name']);

        // An access token is not a Rocket Auth session.
        $this->api('GET', '/api/me', authorization: 'Bearer '.$tokens['access_token']);
        $this->assertStatus(401);

        // Second time: the consent is remembered.
        $check = $this->api('GET', '/api/oauth/authorize?'.http_build_query($this->params()), authorization: $jwt);
        self::assertFalse($check['consentRequired']);
        $consents = $this->api('GET', '/api/consents', authorization: $jwt);
        self::assertSame('Démo CRM', $consents[0]['clientName']);
    }

    public function testCodesAreSingleUseAndBoundToTheirRequest(): void
    {
        $this->client(trusted: true);
        $alice = $this->createUser('alice@example.org');

        $code = $this->authorize($alice);
        $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => 'a-wrong-verifier-that-is-long-enough-0123456789']);
        $this->assertStatus(400);

        $code = $this->authorize($alice);
        $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://evil.example/cb', 'code_verifier' => self::VERIFIER]);
        $this->assertStatus(400);

        $code = $this->authorize($alice);
        $first = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER]);
        $this->assertStatus(200);
        $replay = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER]);
        $this->assertStatus(400);
        self::assertSame('invalid_grant', $replay['error']);
        // The replay revoked what the code had issued.
        $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token']]);
        $this->assertStatus(400);

        // Wrong client secret.
        $this->client->request('POST', '/oauth/token', ['grant_type' => 'authorization_code', 'code' => 'x'], server: ['PHP_AUTH_USER' => 'crm', 'PHP_AUTH_PW' => 'nope']);
        $this->assertStatus(401);
    }

    public function testRefreshTokensRotateAndDetectReuse(): void
    {
        $this->client(trusted: true);
        $alice = $this->createUser('alice@example.org');
        $code = $this->authorize($alice);
        $tokens = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER]);

        $refreshed = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]);
        $this->assertStatus(200);
        self::assertNotSame($tokens['refresh_token'], $refreshed['refresh_token']);
        self::assertArrayHasKey('id_token', $refreshed);

        // The old one was rotated: presenting it again revokes the new one too.
        $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]);
        $this->assertStatus(400);
        $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshed['refresh_token']]);
        $this->assertStatus(400);
    }

    public function testRequestErrorsGoBackToTheApplicationOrStayWithTheUser(): void
    {
        $this->client();
        $jwt = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));

        // Unknown redirect URI: never redirected there.
        $response = $this->api('GET', '/api/oauth/authorize?'.http_build_query($this->params(['redirect_uri' => 'https://evil.example/cb'])), authorization: $jwt);
        $this->assertStatus(400);
        self::assertArrayNotHasKey('redirectUrl', $response);

        // Scope not allowed: back to the application with the error.
        $response = $this->api('GET', '/api/oauth/authorize?'.http_build_query($this->params(['scope' => 'openid admin'])), authorization: $jwt);
        self::assertStringStartsWith(self::REDIRECT.'?error=invalid_scope', $response['redirectUrl']);

        // prompt=none without consent.
        $response = $this->api('GET', '/api/oauth/authorize?'.http_build_query($this->params(['prompt' => 'none'])), authorization: $jwt);
        self::assertStringContainsString('error=consent_required', $response['redirectUrl']);

        // Refusal.
        $response = $this->api('POST', '/api/oauth/authorize', ['params' => $this->params(), 'approve' => false], $jwt);
        self::assertStringContainsString('error=access_denied', $response['redirectUrl']);
        self::assertStringContainsString('state=st4te', $response['redirectUrl']);

        // Not signed in with prompt=none: public cancellation.
        $response = $this->api('POST', '/api/oauth/authorize/cancel', ['params' => $this->params(), 'error' => 'login_required']);
        $this->assertStatus(200);
        self::assertStringContainsString('error=login_required', $response['redirectUrl']);
    }

    public function testPublicClientsMustUsePkce(): void
    {
        $this->client(trusted: true, confidential: false);
        $alice = $this->createUser('alice@example.org');
        $response = $this->api('GET', '/api/oauth/authorize?'.http_build_query(array_diff_key($this->params(), ['code_challenge' => 0, 'code_challenge_method' => 0])), authorization: 'Bearer '.$this->jwtFor($alice));
        self::assertStringContainsString('error=invalid_request', $response['redirectUrl']);

        $code = $this->authorize($alice);
        $tokens = $this->token(['grant_type' => 'authorization_code', 'client_id' => 'crm', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER], basic: false);
        $this->assertStatus(200);
        self::assertArrayHasKey('access_token', $tokens);
    }

    public function testClientCredentials(): void
    {
        $this->client(grants: ['client_credentials']);
        $tokens = $this->token(['grant_type' => 'client_credentials', 'scope' => 'openid files.read']);
        $this->assertStatus(200);
        self::assertSame('files.read', $tokens['scope']);
        self::assertArrayNotHasKey('refresh_token', $tokens);
        self::assertArrayNotHasKey('id_token', $tokens);
        [, $claims] = Jwt::decode($tokens['access_token']);
        self::assertSame('crm', $claims['sub']);

        $this->token(['grant_type' => 'authorization_code', 'code' => 'x']);
        $this->assertStatus(400);
    }

    public function testWithdrawingAConsentRevokesTheApplicationTokens(): void
    {
        $this->client();
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org');
        $code = $this->authorize($alice);
        $tokens = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER]);
        $consent = $this->api('GET', '/api/consents', authorization: 'Bearer '.$this->jwtFor($alice))[0];

        // Someone else's consent: not visible, not deletable.
        self::assertSame([], $this->api('GET', '/api/consents', authorization: 'Bearer '.$this->jwtFor($bob)));
        $this->api('DELETE', '/api/consents/'.$consent['id'], authorization: 'Bearer '.$this->jwtFor($bob));
        $this->assertStatus(403);

        $this->api('DELETE', '/api/consents/'.$consent['id'], authorization: 'Bearer '.$this->jwtFor($alice));
        $this->assertStatus(204);
        $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]);
        $this->assertStatus(400);
    }

    public function testDisabledAccountsAndClientsStop(): void
    {
        $client = $this->client(trusted: true);
        $alice = $this->createUser('alice@example.org');
        $code = $this->authorize($alice);
        $this->em()->getRepository(User::class)->find($alice->getId())->setEnabled(false);
        $this->em()->flush();
        $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER]);
        $this->assertStatus(400);

        $this->em()->getRepository(OAuthClient::class)->find($client->getId())->setEnabled(false);
        $this->em()->flush();
        $response = $this->api('GET', '/api/oauth/authorize?'.http_build_query($this->params()), authorization: 'Bearer '.$this->jwtFor($this->createUser('bob@example.org')));
        $this->assertStatus(400);
        self::assertSame('invalid_client', $response['error']);
    }

    public function testRpInitiatedLogoutOnlyFollowsRegisteredUris(): void
    {
        $this->client();
        $ok = $this->api('GET', '/api/oauth/logout?client_id=crm&post_logout_redirect_uri='.urlencode('https://crm.example.org/').'&state=x');
        self::assertSame('https://crm.example.org/?state=x', $ok['redirectUrl']);
        $ko = $this->api('GET', '/api/oauth/logout?client_id=crm&post_logout_redirect_uri='.urlencode('https://evil.example/'));
        self::assertNull($ko['redirectUrl']);
    }

    public function testAdminsManageClientsWithSecretsShownOnce(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_ADMIN']);
        $jwt = 'Bearer '.$this->jwtFor($admin);

        $created = $this->api('POST', '/api/oauth/clients', ['name' => 'Rocket Mailer', 'redirectUris' => ['http://localhost:3000/auth/callback']], $jwt);
        $this->assertStatus(201);
        self::assertStringStartsWith('rocket-mailer-', $created['clientId']);
        self::assertStringStartsWith('ras_', $created['plainSecret']);

        $fetched = $this->api('GET', '/api/oauth/clients/'.$created['id'], authorization: $jwt);
        self::assertArrayNotHasKey('plainSecret', $fetched);
        self::assertSame(substr($created['plainSecret'], 0, 10), $fetched['secretHint']);

        $this->api('POST', '/api/oauth/clients', ['name' => 'Bad', 'redirectUris' => ['javascript:alert(1)']], $jwt);
        $this->assertStatus(422);
        $this->api('POST', '/api/oauth/clients', ['name' => 'Bad', 'redirectUris' => ['https://x.example/cb'], 'allowedScopes' => ['admin']], $jwt);
        $this->assertStatus(422);

        $rotated = $this->api('POST', '/api/oauth/clients/'.$created['id'].'/regenerate-secret', authorization: $jwt);
        $this->assertStatus(200);
        self::assertNotSame($created['plainSecret'], $rotated['secret']);

        $this->api('GET', '/api/oauth/clients', authorization: 'Bearer '.$this->jwtFor($this->createUser('alice@example.org')));
        $this->assertStatus(403);
    }

    public function testDashboardShowsSignIns(): void
    {
        $this->client(trusted: true);
        $admin = $this->createUser('admin@example.org', ['ROLE_ADMIN']);
        $this->authorize($admin);
        $this->api('POST', '/api/auth/login', ['email' => 'admin@example.org', 'password' => 'correct-horse-battery']);

        $stats = $this->api('GET', '/api/dashboard', authorization: 'Bearer '.$this->jwtFor($admin));
        $this->assertStatus(200);
        $kpis = array_column($stats['kpis'], null, 'id');
        self::assertSame(1, $kpis['authorizations']['value']);
        self::assertSame(1, $kpis['logins']['value']);
        self::assertSame(1, $kpis['clients']['value']);
        self::assertSame(1, end($stats['daily'])['authorize']);
        self::assertContains('Démo CRM', array_column($stats['recent']['items'], 'title'));
    }
}
