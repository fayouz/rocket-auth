<?php

namespace App\Oidc;

use App\Entity\AuthenticationServer;
use App\Security\SecretBox;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Relying party of an OpenID Connect provider (authorization code flow with PKCE, confidential client).
 * The browser gets the authorization endpoint (public URL); every server-to-server call goes through the internal URL when one is set.
 */
class OidcClient
{
    private const LEEWAY = 60;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly SecretBox $secrets,
    ) {
    }

    /**
     * The provider's discovery document (cached for 10 minutes).
     *
     * @return array<string, mixed>
     */
    public function discover(AuthenticationServer $server, bool $fresh = false): array
    {
        $key = 'oidc_discovery_'.hash('xxh128', $server->getUrl().'|'.$server->getInternalUrl());
        if ($fresh) {
            $this->cache->delete($key);
        }

        return $this->cache->get($key, function (ItemInterface $item) use ($server): array {
            $item->expiresAfter(600);

            return $this->fetchDiscovery($server->getUrl(), $server->getInternalUrl());
        });
    }

    /**
     * Reads and checks a discovery document (also used to test a configuration before saving it).
     *
     * @return array<string, mixed>
     */
    public function fetchDiscovery(string $issuer, string $internalUrl = ''): array
    {
        $issuer = rtrim($issuer, '/');
        $metadata = $this->getJson($this->internalize($issuer.'/.well-known/openid-configuration', $issuer, $internalUrl));
        if (rtrim((string) ($metadata['issuer'] ?? ''), '/') !== $issuer) {
            throw new OidcException(sprintf('The provider announces the issuer "%s" instead of "%s".', (string) ($metadata['issuer'] ?? ''), $issuer));
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $endpoint) {
            if (!\is_string($metadata[$endpoint] ?? null) || '' === $metadata[$endpoint]) {
                throw new OidcException(sprintf('The discovery document has no "%s".', $endpoint));
            }
        }

        return $metadata;
    }

    /**
     * Exchanges the authorization code and returns the verified ID token claims (completed by the userinfo endpoint when needed).
     *
     * @return array<string, mixed>
     */
    public function authenticate(AuthenticationServer $server, string $code, string $codeVerifier, string $redirectUri, ?string $nonce): array
    {
        $metadata = $this->discover($server);
        $secret = '' === $server->getEncryptedClientSecret() ? '' : $this->secrets->decrypt($server->getEncryptedClientSecret());

        $tokens = $this->request('POST', $this->internalize((string) $metadata['token_endpoint'], $server->getUrl(), $server->getInternalUrl()), [
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'code_verifier' => $codeVerifier,
                'client_id' => $server->getClientId(),
                'client_secret' => $secret,
            ],
        ]);
        $idToken = $tokens['id_token'] ?? null;
        if (!\is_string($idToken)) {
            throw new OidcException('The provider returned no ID token.');
        }

        $claims = $this->verifyIdToken($server, $metadata, $idToken, $nonce);

        if ((!isset($claims['email']) || !\array_key_exists('groups', $claims)) && \is_string($metadata['userinfo_endpoint'] ?? null) && \is_string($tokens['access_token'] ?? null)) {
            $userinfo = $this->getJson(
                $this->internalize($metadata['userinfo_endpoint'], $server->getUrl(), $server->getInternalUrl()),
                ['auth_bearer' => $tokens['access_token']],
            );
            if (($userinfo['sub'] ?? null) === $claims['sub']) {
                $claims += $userinfo;
            }
        }

        return $claims;
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private function verifyIdToken(AuthenticationServer $server, array $metadata, string $idToken, ?string $nonce): array
    {
        $jwksUri = $this->internalize((string) $metadata['jwks_uri'], $server->getUrl(), $server->getInternalUrl());
        $keys = $this->getJson($jwksUri)['keys'] ?? [];
        try {
            $claims = Jwt::verify($idToken, \is_array($keys) ? array_values(array_filter($keys, 'is_array')) : []);
        } catch (OidcException) {
            // Keys may have rotated: read them once more.
            $keys = $this->getJson($jwksUri)['keys'] ?? [];
            $claims = Jwt::verify($idToken, \is_array($keys) ? array_values(array_filter($keys, 'is_array')) : []);
        }

        $now = time();
        $audience = (array) ($claims['aud'] ?? []);
        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== rtrim($server->getUrl(), '/')) {
            throw new OidcException('The ID token comes from another issuer.');
        }
        if (!\in_array($server->getClientId(), $audience, true)) {
            throw new OidcException('The ID token was issued for another client.');
        }
        if (!\is_int($claims['exp'] ?? null) || $claims['exp'] < $now - self::LEEWAY) {
            throw new OidcException('The ID token has expired.');
        }
        if (\is_int($claims['iat'] ?? null) && $claims['iat'] > $now + self::LEEWAY) {
            throw new OidcException('The ID token was issued in the future.');
        }
        if (null !== $nonce && '' !== $nonce && !hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new OidcException('The ID token does not match this sign-in (nonce).');
        }
        if (!\is_string($claims['sub'] ?? null) || '' === $claims['sub']) {
            throw new OidcException('The ID token has no subject.');
        }

        return $claims;
    }

    /** Rewrites a public URL of the provider to its internal base URL, when one is set. */
    public function internalize(string $url, string $issuer, string $internalUrl): string
    {
        $issuer = rtrim($issuer, '/');
        if ('' === $internalUrl) {
            return $url;
        }
        $origin = preg_replace('#^(https?://[^/]+).*$#', '$1', $issuer);

        return str_starts_with($url, (string) $origin) ? rtrim($internalUrl, '/').substr($url, \strlen((string) $origin)) : $url;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function getJson(string $url, array $options = []): array
    {
        return $this->request('GET', $url, $options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, array $options): array
    {
        try {
            $response = $this->httpClient->request($method, $url, $options + ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (HttpExceptionInterface|\JsonException $e) {
            throw new OidcException(sprintf('The authentication server cannot be reached (%s).', $e->getMessage()), previous: $e);
        }
        if ($status >= 400) {
            $error = \is_string($data['error_description'] ?? null) ? $data['error_description'] : (\is_string($data['error'] ?? null) ? $data['error'] : 'HTTP '.$status);
            throw new OidcException(sprintf('The authentication server refused the request: %s.', $error));
        }

        return $data;
    }
}
