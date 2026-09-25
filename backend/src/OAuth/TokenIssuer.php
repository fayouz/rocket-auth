<?php

namespace App\OAuth;

use App\Entity\OAuthClient;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Oidc\Jwt;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Issues the token response of the token endpoint: access token (JWT), ID token, refresh token. */
class TokenIssuer
{
    public function __construct(
        private readonly SigningKeys $keys,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'OIDC_ISSUER')] private readonly string $issuer,
        #[Autowire(env: 'int:OIDC_ACCESS_TOKEN_TTL')] private readonly int $accessTokenTtl = 900,
        #[Autowire(env: 'int:OIDC_ID_TOKEN_TTL')] private readonly int $idTokenTtl = 3600,
        #[Autowire(env: 'int:OIDC_REFRESH_TOKEN_TTL')] private readonly int $refreshTokenTtl = 2592000,
    ) {
    }

    public function issuer(): string
    {
        return rtrim($this->issuer, '/');
    }

    /**
     * @param list<string> $scopes
     *
     * @return array<string, mixed>
     */
    public function forUser(OAuthClient $client, User $user, array $scopes, \DateTimeImmutable $authTime, ?string $nonce = null, bool $withRefreshToken = true): array
    {
        $now = $this->clock->now();
        $accessToken = $this->accessToken($client, (string) $user->getId(), $scopes, $now);
        $response = [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTokenTtl,
            'scope' => implode(' ', $scopes),
        ];

        if (\in_array('openid', $scopes, true)) {
            $response['id_token'] = Jwt::sign([
                'iss' => $this->issuer(),
                'sub' => (string) $user->getId(),
                'aud' => $client->getClientId(),
                'azp' => $client->getClientId(),
                'iat' => $now->getTimestamp(),
                'exp' => $now->getTimestamp() + $this->idTokenTtl,
                'auth_time' => $authTime->getTimestamp(),
                'at_hash' => Jwt::base64UrlEncode(substr(hash('sha256', $accessToken, true), 0, 16)),
            ] + (null === $nonce || '' === $nonce ? [] : ['nonce' => $nonce]) + Scopes::claims($user, $scopes), $this->keys->privateKey(), $this->keys->kid());
        }

        if ($withRefreshToken && $client->allowsGrant('refresh_token')) {
            $refresh = 'rar_'.bin2hex(random_bytes(32));
            $this->em->persist(new RefreshToken(hash('sha256', $refresh), $client, $user, $scopes, $authTime, $now, $now->modify(\sprintf('+%d seconds', $this->refreshTokenTtl))));
            $response['refresh_token'] = $refresh;
        }

        return $response;
    }

    /**
     * client_credentials: the application acts for itself (subject: its client ID), no ID token nor refresh token.
     *
     * @param list<string> $scopes
     *
     * @return array<string, mixed>
     */
    public function forClient(OAuthClient $client, array $scopes): array
    {
        return [
            'access_token' => $this->accessToken($client, $client->getClientId(), $scopes, $this->clock->now()),
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTokenTtl,
            'scope' => implode(' ', $scopes),
        ];
    }

    /**
     * Verifies an access token issued here and returns its claims.
     *
     * @return array<string, mixed>
     */
    public function verifyAccessToken(string $token): array
    {
        try {
            $claims = Jwt::verify($token, [$this->keys->publicJwk()]);
        } catch (\App\Oidc\OidcException) {
            throw new OAuthException('invalid_token', 'The access token is invalid.', 401);
        }
        if (($claims['iss'] ?? null) !== $this->issuer() || 'access' !== ($claims['token_use'] ?? null)) {
            throw new OAuthException('invalid_token', 'The access token is invalid.', 401);
        }
        if (!\is_int($claims['exp'] ?? null) || $claims['exp'] < $this->clock->now()->getTimestamp()) {
            throw new OAuthException('invalid_token', 'The access token has expired.', 401);
        }

        return $claims;
    }

    /** @param list<string> $scopes */
    private function accessToken(OAuthClient $client, string $subject, array $scopes, \DateTimeImmutable $now): string
    {
        return Jwt::sign([
            'iss' => $this->issuer(),
            'sub' => $subject,
            'aud' => $client->getClientId(),
            'client_id' => $client->getClientId(),
            'scope' => implode(' ', $scopes),
            'token_use' => 'access',
            'iat' => $now->getTimestamp(),
            'exp' => $now->getTimestamp() + $this->accessTokenTtl,
            'jti' => bin2hex(random_bytes(16)),
        ], $this->keys->privateKey(), $this->keys->kid());
    }
}
