<?php

namespace App\Tests\Functional;

use App\Entity\OAuthClient;
use App\Message\SendBackchannelLogout;
use App\MessageHandler\SendBackchannelLogoutHandler;
use App\OAuth\TokenIssuer;
use App\Tests\ApiTestTrait;
use App\Tests\Support\HttpMock;
use Rocket\Core\Entity\User;
use Rocket\Core\Oidc\Jwt;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Back-channel logout of the applications, their declaration by the bricks, and calls between bricks (client credentials). */
final class SuiteIdentityTest extends WebTestCase
{
    use ApiTestTrait {
        setUp as private apiSetUp;
    }

    private const SECRET = 'a-demo-client-secret-long-enough';
    private const VERIFIER = 'the-pkce-code-verifier-of-this-test-0123456789';

    /** @var list<array{url: string, token: string}> */
    private array $logouts = [];
    private int $status = 200;

    protected function setUp(): void
    {
        $this->apiSetUp();
        HttpMock::reset();
        foreach (['https://crm.example.org', 'https://erp.example.org'] as $origin) {
            HttpMock::on($origin.'/backchannel', function (string $method, string $url, array $options) {
                parse_str((string) $options['body'], $body);
                $this->logouts[] = ['url' => $url, 'token' => $body['logout_token']];

                return new MockResponse('', ['http_code' => $this->status]);
            });
        }
    }

    public function testDiscoveryAdvertisesBackchannelLogout(): void
    {
        $this->client->request('GET', '/.well-known/openid-configuration');
        $discovery = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($discovery['backchannel_logout_supported']);
        self::assertTrue($discovery['backchannel_logout_session_supported']);
        self::assertContains('client_credentials', $discovery['grant_types_supported']);
    }

    public function testSigningOutEndsTheSignInsOfThatSession(): void
    {
        $this->oauthClient('crm');
        $this->oauthClient('erp');
        $alice = $this->createUser('alice@example.org');
        $session = $this->jwtFor($alice);
        $otherSession = $this->jwtFor($alice);
        $sid = Jwt::decode($session)[1]['sid'];
        self::assertNotSame($sid, Jwt::decode($otherSession)[1]['sid']);

        $crm = $this->signIn('crm', $session);
        $erp = $this->signIn('erp', $otherSession);
        self::assertSame($sid, Jwt::decode($crm['id_token'])[1]['sid']);

        $this->api('POST', '/api/auth/logout', authorization: 'Bearer '.$session);
        $this->assertStatus(204);

        // The refresh tokens of this session are revoked, not the others.
        $this->token('crm', ['grant_type' => 'refresh_token', 'refresh_token' => $crm['refresh_token']]);
        $this->assertStatus(400);
        $this->token('erp', ['grant_type' => 'refresh_token', 'refresh_token' => $erp['refresh_token']]);
        $this->assertStatus(200);

        // Only the application of this session is told, with a signed logout token.
        self::assertCount(1, $this->logouts);
        self::assertSame('https://crm.example.org/backchannel', $this->logouts[0]['url']);
        [$header] = Jwt::decode($this->logouts[0]['token']);
        self::assertSame('logout+jwt', $header['typ']);
        $claims = Jwt::verify($this->logouts[0]['token'], $this->keys());
        self::assertSame('http://localhost:8100', $claims['iss']);
        self::assertSame('crm', $claims['aud']);
        self::assertSame((string) $alice->getId(), $claims['sub']);
        self::assertSame($sid, $claims['sid']);
        self::assertSame([TokenIssuer::BACKCHANNEL_LOGOUT_EVENT => []], $claims['events']);
        self::assertArrayNotHasKey('nonce', $claims);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $claims['jti']);
        self::assertEqualsWithDelta(time(), $claims['iat'], 5);
        self::assertGreaterThan($claims['iat'], $claims['exp']);
    }

    public function testDisablingOrDeletingAnAccountSignsItOutEverywhere(): void
    {
        $this->oauthClient('crm');
        $this->oauthClient('erp');
        $this->oauthClient('other');
        $admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $alice = $this->createUser('alice@example.org');
        $crm = $this->signIn('crm', $this->jwtFor($alice));
        $this->signIn('erp', $this->jwtFor($alice));

        $this->api('PATCH', '/api/users/'.$alice->getId(), ['enabled' => false], $admin);
        $this->assertStatus(200);
        self::assertSame(['https://crm.example.org/backchannel', 'https://erp.example.org/backchannel'], $this->sortedUrls());
        foreach ($this->logouts as $logout) {
            $claims = Jwt::verify($logout['token'], $this->keys());
            self::assertSame((string) $alice->getId(), $claims['sub']);
            // Every session.
            self::assertArrayNotHasKey('sid', $claims);
        }
        $this->token('crm', ['grant_type' => 'refresh_token', 'refresh_token' => $crm['refresh_token']]);
        $this->assertStatus(400);

        // Editing an account that stays enabled tells nobody.
        $this->logouts = [];
        $bob = $this->createUser('bob@example.org');
        $this->signIn('crm', $this->jwtFor($bob));
        $this->api('PATCH', '/api/users/'.$bob->getId(), ['firstName' => 'Bob'], $admin);
        $this->assertStatus(200);
        self::assertSame([], $this->logouts);

        $this->api('DELETE', '/api/users/'.$bob->getId(), authorization: $admin);
        $this->assertStatus(204);
        self::assertSame(['https://crm.example.org/backchannel'], $this->sortedUrls());
        self::assertSame((string) $bob->getId(), Jwt::verify($this->logouts[0]['token'], $this->keys())['sub']);
    }

    public function testRefusedLogoutTokensAreRetried(): void
    {
        $client = $this->oauthClient('crm');
        $this->status = 503;
        $handler = static::getContainer()->get(SendBackchannelLogoutHandler::class);
        try {
            $handler(new SendBackchannelLogout('crm', 'user-1', 'sid-1'));
            self::fail('A refused logout token must fail the message (retried by Messenger).');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('HTTP 503', $e->getMessage());
        }

        // Nothing to send without an endpoint.
        $this->em()->getRepository(OAuthClient::class)->find($client->getId())->setBackchannelLogoutUri(null);
        $this->em()->flush();
        $handler(new SendBackchannelLogout('crm', 'user-1'));
        self::assertCount(1, $this->logouts);
    }

    public function testBricksDeclareTheirBackchannelLogoutEndpoint(): void
    {
        $this->oauthClient('crm', backchannel: null);
        $this->client->request('POST', '/oauth/suite/register', ['backchannel_logout_uri' => 'http://crm-api/api/auth/oidc/backchannel-logout'], server: ['PHP_AUTH_USER' => 'crm', 'PHP_AUTH_PW' => self::SECRET]);
        $this->assertStatus(200);
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('http://crm-api/api/auth/oidc/backchannel-logout', $response['backchannel_logout_uri']);
        self::assertSame('http://crm-api/api/auth/oidc/backchannel-logout', $this->em()->getRepository(OAuthClient::class)->findOneBy(['clientId' => 'crm'])->getBackchannelLogoutUri());

        $this->client->request('POST', '/oauth/suite/register', ['backchannel_logout_uri' => 'javascript:alert(1)'], server: ['PHP_AUTH_USER' => 'crm', 'PHP_AUTH_PW' => self::SECRET]);
        $this->assertStatus(400);
        $this->client->request('POST', '/oauth/suite/register', ['backchannel_logout_uri' => 'https://evil.example/'], server: ['PHP_AUTH_USER' => 'crm', 'PHP_AUTH_PW' => 'wrong-secret']);
        $this->assertStatus(401);

        // The administrators see and edit it on the client.
        $admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $client = $this->em()->getRepository(OAuthClient::class)->findOneBy(['clientId' => 'crm']);
        $this->api('PATCH', '/api/oauth/clients/'.$client->getId(), ['backchannelLogoutUri' => 'ftp://nope'], $admin);
        $this->assertStatus(422);
        $patched = $this->api('PATCH', '/api/oauth/clients/'.$client->getId(), ['backchannelLogoutUri' => 'https://crm.example.org/backchannel'], $admin);
        $this->assertStatus(200);
        self::assertSame('https://crm.example.org/backchannel', $patched['backchannelLogoutUri']);
    }

    public function testClientCredentialsForAnotherApplication(): void
    {
        $this->oauthClient('rocket-cloud', grants: ['authorization_code', 'refresh_token', 'client_credentials']);
        $this->oauthClient('rocket-mailer');
        $disabled = $this->oauthClient('rocket-print');
        $this->em()->getRepository(OAuthClient::class)->find($disabled->getId())->setEnabled(false);
        $this->em()->flush();

        $tokens = $this->token('rocket-cloud', ['grant_type' => 'client_credentials', 'audience' => 'rocket-mailer']);
        $this->assertStatus(200);
        self::assertSame(300, $tokens['expires_in']);
        $claims = Jwt::verify($tokens['access_token'], $this->keys());
        self::assertSame(['http://localhost:8100', 'rocket-cloud', 'rocket-mailer', 'rocket-cloud', 'rocket-cloud'], [$claims['iss'], $claims['sub'], $claims['aud'], $claims['azp'], $claims['client_id']]);
        self::assertSame(300, $claims['exp'] - $claims['iat']);

        // client_secret_post too.
        $this->client->request('POST', '/oauth/token', ['grant_type' => 'client_credentials', 'audience' => 'rocket-mailer', 'client_id' => 'rocket-cloud', 'client_secret' => self::SECRET]);
        $this->assertStatus(200);

        foreach (['unknown-app', 'rocket-print'] as $audience) {
            $error = $this->token('rocket-cloud', ['grant_type' => 'client_credentials', 'audience' => $audience]);
            $this->assertStatus(400);
            self::assertSame('invalid_target', $error['error']);
        }

        // An application must be allowed to use the grant.
        $error = $this->token('rocket-mailer', ['grant_type' => 'client_credentials', 'audience' => 'rocket-cloud']);
        self::assertSame('unauthorized_client', $error['error']);
    }

    private function oauthClient(string $clientId, ?string $backchannel = 'default', array $grants = ['authorization_code', 'refresh_token']): OAuthClient
    {
        $host = explode('-', $clientId)[0];
        $client = (new OAuthClient())->setName(ucfirst($clientId))->setClientId($clientId)
            ->setRedirectUris(['https://'.$host.'.example.org/auth/callback'])->setTrusted(true)->setGrantTypes($grants)
            ->setBackchannelLogoutUri('default' === $backchannel ? 'https://'.$clientId.'.example.org/backchannel' : $backchannel);
        $client->useSecret(self::SECRET);
        $this->em()->persist($client);
        $this->em()->flush();

        return $client;
    }

    /** Signs in to the application from a Rocket Auth session, and returns its tokens. */
    private function signIn(string $clientId, string $session): array
    {
        $redirect = 'https://'.explode('-', $clientId)[0].'.example.org/auth/callback';
        $response = $this->api('POST', '/api/oauth/authorize', ['approve' => true, 'params' => [
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirect, 'scope' => 'openid email',
            'code_challenge' => Jwt::pkceChallenge(self::VERIFIER), 'code_challenge_method' => 'S256',
        ]], 'Bearer '.$session);
        $this->assertStatus(200);
        parse_str((string) parse_url($response['redirectUrl'], \PHP_URL_QUERY), $query);
        $tokens = $this->token($clientId, ['grant_type' => 'authorization_code', 'code' => $query['code'], 'redirect_uri' => $redirect, 'code_verifier' => self::VERIFIER]);
        $this->assertStatus(200);

        return $tokens;
    }

    /** @return array<string, mixed> */
    private function token(string $clientId, array $body): array
    {
        $this->client->request('POST', '/oauth/token', $body, server: ['PHP_AUTH_USER' => $clientId, 'PHP_AUTH_PW' => self::SECRET]);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return list<array<string, mixed>> */
    private function keys(): array
    {
        $this->client->request('GET', '/oauth/jwks');

        return json_decode((string) $this->client->getResponse()->getContent(), true)['keys'];
    }

    /** @return list<string> */
    private function sortedUrls(): array
    {
        $urls = array_column($this->logouts, 'url');
        sort($urls);

        return $urls;
    }
}
