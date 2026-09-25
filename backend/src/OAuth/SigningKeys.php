<?php

namespace App\OAuth;

use App\Oidc\Jwt;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * RSA key signing the ID and access tokens (RS256), published as JWKS. Separate from the session keys (Lexik), so an
 * access token can never be used as a Rocket Auth session. Generated on first use when missing (OIDC_PRIVATE_KEY).
 */
class SigningKeys
{
    private ?\OpenSSLAsymmetricKey $key = null;

    public function __construct(
        #[Autowire(env: 'resolve:OIDC_PRIVATE_KEY')] private readonly string $path,
    ) {
    }

    public function privateKey(): \OpenSSLAsymmetricKey
    {
        if (null !== $this->key) {
            return $this->key;
        }
        if (!is_file($this->path)) {
            $this->generate();
        }
        $key = openssl_pkey_get_private((string) file_get_contents($this->path));
        if (false === $key) {
            throw new \RuntimeException(\sprintf('The OpenID Connect signing key "%s" cannot be read.', $this->path));
        }

        return $this->key = $key;
    }

    /** @return array{kty: string, use: string, alg: string, kid: string, n: string, e: string} */
    public function publicJwk(): array
    {
        return Jwt::publicJwk($this->privateKey());
    }

    public function kid(): string
    {
        return $this->publicJwk()['kid'];
    }

    /** @return bool false when a key already exists */
    public function generate(bool $overwrite = false): bool
    {
        if (is_file($this->path) && !$overwrite) {
            return false;
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        if (false === $key || !openssl_pkey_export($key, $pem)) {
            throw new \RuntimeException('The OpenID Connect signing key cannot be generated.');
        }
        (new Filesystem())->dumpFile($this->path, $pem);
        @chmod($this->path, 0o600);
        $this->key = null;

        return true;
    }
}
