<?php

namespace App\OAuth;

use App\Entity\AuthorizationCode;
use App\Entity\Consent;
use App\Entity\OAuthClient;
use App\Entity\RefreshToken;
use App\Entity\SignInEvent;
use Rocket\Core\Entity\User;
use App\Repository\AuthorizationCodeRepository;
use App\Repository\ConsentRepository;
use App\Repository\OAuthClientRepository;
use App\Repository\RefreshTokenRepository;
use Rocket\Core\Oidc\Jwt;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * OAuth 2.0 / OpenID Connect authorization server: authorization code flow with PKCE, refresh token rotation,
 * client credentials. Stateless for the browser: the signed-in user comes from the Rocket Auth session (JWT).
 */
class AuthorizationServer
{
    private const CODE_TTL = 60;

    public function __construct(
        private readonly OAuthClientRepository $clients,
        private readonly AuthorizationCodeRepository $codes,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly ConsentRepository $consents,
        private readonly TokenIssuer $tokens,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Validates the parameters of an authorization request. Errors about the client or the redirect URI must be shown
     * to the user ($redirectable false); the others are sent back to the application.
     *
     * @param array<string, mixed> $query
     *
     * @throws AuthorizationError
     */
    public function validateAuthorization(array $query): AuthorizationRequest
    {
        $param = static fn (string $name): ?string => \is_string($query[$name] ?? null) && '' !== $query[$name] ? $query[$name] : null;

        $client = null === $param('client_id') ? null : $this->clients->findOneBy(['clientId' => $param('client_id')]);
        if (null === $client || !$client->isEnabled()) {
            throw new AuthorizationError('invalid_client', 'Unknown or disabled application.');
        }
        $redirectUri = $param('redirect_uri') ?? (1 === \count($client->getRedirectUris()) ? $client->getRedirectUris()[0] : null);
        if (null === $redirectUri || !$client->allowsRedirectUri($redirectUri)) {
            throw new AuthorizationError('invalid_request', 'This redirect URI is not registered for the application.');
        }

        $scopes = Scopes::parse($param('scope'));
        $request = new AuthorizationRequest(
            client: $client,
            redirectUri: $redirectUri,
            scopes: $scopes,
            state: $param('state'),
            nonce: $param('nonce'),
            codeChallenge: $param('code_challenge'),
            prompt: $param('prompt'),
        );

        if ('code' !== $param('response_type')) {
            throw new AuthorizationError('unsupported_response_type', 'Only response_type=code is supported.', $request);
        }
        if (!$client->allowsGrant('authorization_code')) {
            throw new AuthorizationError('unauthorized_client', 'This application cannot use the authorization code flow.', $request);
        }
        if ([] === $scopes) {
            throw new AuthorizationError('invalid_scope', 'The scope parameter is required.', $request);
        }
        if ([] !== $unknown = array_diff($scopes, $client->getAllowedScopes())) {
            throw new AuthorizationError('invalid_scope', \sprintf('Scope(s) not allowed for this application: %s.', implode(', ', $unknown)), $request);
        }
        if (null !== $request->codeChallenge) {
            if ('S256' !== ($param('code_challenge_method') ?? 'plain')) {
                throw new AuthorizationError('invalid_request', 'Only the S256 PKCE method is supported.', $request);
            }
            if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $request->codeChallenge)) {
                throw new AuthorizationError('invalid_request', 'Malformed PKCE code challenge.', $request);
            }
        } elseif (!$client->isConfidential()) {
            throw new AuthorizationError('invalid_request', 'PKCE (code_challenge, S256) is required for public applications.', $request);
        }
        if (null !== $request->prompt && [] !== array_diff(explode(' ', $request->prompt), ['none', 'login', 'consent', 'select_account'])) {
            throw new AuthorizationError('invalid_request', 'Unsupported prompt value.', $request);
        }

        return $request;
    }

    /** Whether the user must be asked before the application gets these scopes. */
    public function needsConsent(AuthorizationRequest $request, User $user): bool
    {
        if ($request->client->isTrusted()) {
            return false;
        }
        if (null !== $request->prompt && str_contains($request->prompt, 'consent')) {
            return true;
        }
        $consent = $this->consents->findOneBy(['user' => $user, 'client' => $request->client]);

        return null === $consent || !$consent->covers($request->scopes);
    }

    /** The user said yes (or had already): records the consent and returns the redirect with a one-time code. */
    public function approve(AuthorizationRequest $request, User $user, \DateTimeImmutable $authTime): string
    {
        $now = $this->clock->now();
        if (!$request->client->isTrusted()) {
            $consent = $this->consents->findOneBy(['user' => $user, 'client' => $request->client]);
            if (null === $consent) {
                $consent = new Consent($user, $request->client, $now);
                $this->em->persist($consent);
            }
            if (!$consent->covers($request->scopes)) {
                $consent->grant($request->scopes, $now);
            }
            $consent->markUsed($now);
        }

        $code = bin2hex(random_bytes(32));
        $this->em->persist(new AuthorizationCode(
            hash('sha256', $code),
            $request->client,
            $user,
            $request->redirectUri,
            $request->scopes,
            $request->nonce,
            $request->codeChallenge,
            $authTime,
            $now->modify(\sprintf('+%d seconds', self::CODE_TTL)),
        ));
        $this->em->persist(new SignInEvent(SignInEvent::AUTHORIZE, $user, $request->client, $now));
        $this->em->flush();

        return $request->redirect(['code' => $code, 'iss' => $this->tokens->issuer()]);
    }

    public function deny(AuthorizationRequest $request, ?User $user, string $error = 'access_denied', string $description = 'The user refused the authorization.'): string
    {
        if ('access_denied' === $error) {
            $this->em->persist(new SignInEvent(SignInEvent::DENIED, $user, $request->client, $this->clock->now()));
            $this->em->flush();
        }

        return $request->redirect(['error' => $error, 'error_description' => $description]);
    }

    /**
     * The token endpoint (RFC 6749 §3.2): authenticates the client, then handles the grant.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function token(array $body, ?string $basicUser, ?string $basicPassword): array
    {
        $grant = \is_string($body['grant_type'] ?? null) ? $body['grant_type'] : '';
        $client = $this->authenticateClient($body, $basicUser, $basicPassword);
        if (!\in_array($grant, OAuthClient::GRANT_TYPES, true)) {
            throw new OAuthException('unsupported_grant_type', 'Supported grants: authorization_code, refresh_token, client_credentials.');
        }
        if (!$client->allowsGrant($grant)) {
            throw new OAuthException('unauthorized_client', \sprintf('This application cannot use the %s grant.', $grant));
        }
        $client->markUsed($this->clock->now());

        $response = match ($grant) {
            'authorization_code' => $this->exchangeCode($client, $body),
            'refresh_token' => $this->refresh($client, $body),
            'client_credentials' => $this->clientCredentials($client, $body),
        };
        $this->em->flush();

        return $response;
    }

    /** RFC 7009: revokes a refresh token of this client (unknown tokens are silently accepted). */
    public function revoke(array $body, ?string $basicUser, ?string $basicPassword): void
    {
        $client = $this->authenticateClient($body, $basicUser, $basicPassword);
        $token = \is_string($body['token'] ?? null) ? $body['token'] : '';
        $refresh = $this->refreshTokens->findOneBy(['tokenHash' => hash('sha256', $token)]);
        if (null !== $refresh && $refresh->getClient() === $client) {
            $refresh->revoke($this->clock->now());
            $this->em->flush();
        }
    }

    /** Revokes every refresh token of a user for a client (consent withdrawn, reuse detected). */
    public function revokeAll(User $user, OAuthClient $client): void
    {
        $now = $this->clock->now();
        foreach ($this->refreshTokens->findBy(['user' => $user, 'client' => $client]) as $token) {
            $token->revoke($now);
        }
    }

    /** @param array<string, mixed> $body */
    private function authenticateClient(array $body, ?string $basicUser, ?string $basicPassword): OAuthClient
    {
        $clientId = null !== $basicUser && '' !== $basicUser ? urldecode($basicUser) : (\is_string($body['client_id'] ?? null) ? $body['client_id'] : '');
        $secret = null !== $basicUser && '' !== $basicUser ? urldecode((string) $basicPassword) : (\is_string($body['client_secret'] ?? null) ? $body['client_secret'] : '');

        $client = '' === $clientId ? null : $this->clients->findOneBy(['clientId' => $clientId]);
        if (null === $client || !$client->isEnabled()) {
            throw new OAuthException('invalid_client', 'Unknown or disabled client.', 401);
        }
        if ($client->isConfidential() && !$client->checkSecret($secret)) {
            throw new OAuthException('invalid_client', 'Client authentication failed.', 401);
        }

        return $client;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function exchangeCode(OAuthClient $client, array $body): array
    {
        $code = \is_string($body['code'] ?? null) ? $this->codes->findOneBy(['codeHash' => hash('sha256', $body['code'])]) : null;
        $now = $this->clock->now();
        if (null === $code || $code->getClient() !== $client) {
            throw new OAuthException('invalid_grant', 'Unknown authorization code.');
        }
        if ($code->isUsed()) {
            // A code presented twice was intercepted: the tokens issued with it must not survive.
            $this->revokeAll($code->getUser(), $client);
            $this->em->flush();
            throw new OAuthException('invalid_grant', 'This authorization code was already used.');
        }
        if ($code->isExpired($now)) {
            throw new OAuthException('invalid_grant', 'The authorization code has expired.');
        }
        if (($body['redirect_uri'] ?? null) !== $code->getRedirectUri()) {
            throw new OAuthException('invalid_grant', 'The redirect URI does not match the authorization request.');
        }
        if (null !== $code->getCodeChallenge()) {
            $verifier = \is_string($body['code_verifier'] ?? null) ? $body['code_verifier'] : '';
            if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) || !hash_equals($code->getCodeChallenge(), Jwt::pkceChallenge($verifier))) {
                throw new OAuthException('invalid_grant', 'The PKCE code verifier does not match.');
            }
        }
        $code->markUsed($now);

        $user = $code->getUser();
        if (!$user->isEnabled()) {
            throw new OAuthException('invalid_grant', 'This account is disabled.');
        }

        return $this->tokens->forUser($client, $user, $code->getScopes(), $code->getAuthTime(), $code->getNonce());
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function refresh(OAuthClient $client, array $body): array
    {
        $now = $this->clock->now();
        $token = \is_string($body['refresh_token'] ?? null) ? $this->refreshTokens->findOneBy(['tokenHash' => hash('sha256', $body['refresh_token'])]) : null;
        if (null === $token || $token->getClient() !== $client) {
            throw new OAuthException('invalid_grant', 'Unknown refresh token.');
        }
        if ($token->isRevoked()) {
            // Reuse of a rotated token: someone else holds this family.
            $this->revokeAll($token->getUser(), $client);
            $this->em->flush();
            throw new OAuthException('invalid_grant', 'This refresh token was revoked.');
        }
        if (!$token->isActive($now)) {
            throw new OAuthException('invalid_grant', 'The refresh token has expired.');
        }
        $user = $token->getUser();
        if (!$user->isEnabled()) {
            throw new OAuthException('invalid_grant', 'This account is disabled.');
        }

        $scopes = $token->getScopes();
        if (\is_string($body['scope'] ?? null) && '' !== $body['scope']) {
            $requested = Scopes::parse($body['scope']);
            if ([] !== array_diff($requested, $scopes)) {
                throw new OAuthException('invalid_scope', 'A refreshed token cannot get more scopes.');
            }
            $scopes = $requested;
        }
        $token->revoke($now);

        return $this->tokens->forUser($client, $user, $scopes, $token->getAuthTime());
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function clientCredentials(OAuthClient $client, array $body): array
    {
        $scopes = Scopes::parse(\is_string($body['scope'] ?? null) ? $body['scope'] : '');
        // User scopes make no sense without a user.
        $scopes = array_values(array_diff($scopes, ['openid', 'profile', 'email', 'groups', 'offline_access']));

        return $this->tokens->forClient($client, $scopes);
    }
}
