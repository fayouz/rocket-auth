<?php

namespace App\Oidc;

/**
 * Compact JWS (RS256) helpers for OpenID Connect: signing and verifying tokens, and converting RSA keys to and from JWK.
 */
final class Jwt
{
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if (false === $decoded) {
            throw new OidcException('Invalid base64url data.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $claims
     */
    public static function sign(array $claims, \OpenSSLAsymmetricKey $privateKey, string $kid): string
    {
        $header = self::base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid], \JSON_THROW_ON_ERROR));
        $payload = self::base64UrlEncode(json_encode($claims, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        if (!openssl_sign($header.'.'.$payload, $signature, $privateKey, \OPENSSL_ALGO_SHA256)) {
            throw new OidcException('The token cannot be signed.');
        }

        return $header.'.'.$payload.'.'.self::base64UrlEncode($signature);
    }

    /**
     * Header and claims of a token, without checking its signature.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (3 !== \count($parts)) {
            throw new OidcException('Malformed token.');
        }
        try {
            $header = json_decode(self::base64UrlDecode($parts[0]), true, flags: \JSON_THROW_ON_ERROR);
            $claims = json_decode(self::base64UrlDecode($parts[1]), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new OidcException('Malformed token.');
        }
        if (!\is_array($header) || !\is_array($claims)) {
            throw new OidcException('Malformed token.');
        }

        return [$header, $claims];
    }

    /**
     * Verifies an RS256 signature against a set of keys (JWKS) and returns the claims. Time and audience checks are left to the caller.
     *
     * @param list<array<string, mixed>> $keys
     *
     * @return array<string, mixed>
     */
    public static function verify(string $token, array $keys): array
    {
        [$header, $claims] = self::decode($token);
        if ('RS256' !== ($header['alg'] ?? null)) {
            throw new OidcException('Unsupported token algorithm.');
        }
        $kid = $header['kid'] ?? null;
        $candidates = array_values(array_filter($keys, static fn (array $key) => 'RSA' === ($key['kty'] ?? null)
            && (null === $kid || ($key['kid'] ?? null) === $kid)
            && \in_array($key['use'] ?? 'sig', ['sig'], true)));
        [$h, $p, $s] = explode('.', $token);
        $signature = self::base64UrlDecode($s);
        foreach ($candidates as $jwk) {
            if (1 === openssl_verify($h.'.'.$p, $signature, self::jwkToPem($jwk), \OPENSSL_ALGO_SHA256)) {
                return $claims;
            }
        }

        throw new OidcException('Invalid token signature.');
    }

    /** @return array{kty: string, use: string, alg: string, kid: string, n: string, e: string} */
    public static function publicJwk(\OpenSSLAsymmetricKey $key): array
    {
        $details = openssl_pkey_get_details($key);
        if (false === $details || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new OidcException('Not an RSA key.');
        }
        $n = self::base64UrlEncode($details['rsa']['n']);
        $e = self::base64UrlEncode($details['rsa']['e']);

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            // RFC 7638 thumbprint.
            'kid' => self::base64UrlEncode(hash('sha256', json_encode(['e' => $e, 'kty' => 'RSA', 'n' => $n], \JSON_THROW_ON_ERROR), true)),
            'n' => $n,
            'e' => $e,
        ];
    }

    /** @param array<string, mixed> $jwk */
    public static function jwkToPem(array $jwk): string
    {
        if (!\is_string($jwk['n'] ?? null) || !\is_string($jwk['e'] ?? null)) {
            throw new OidcException('Invalid RSA key.');
        }
        $n = self::base64UrlDecode($jwk['n']);
        $e = self::base64UrlDecode($jwk['e']);
        // Positive INTEGERs: a leading 0x00 when the high bit is set.
        $integer = static fn (string $bytes) => "\x02".self::derLength(\strlen($bytes = (\ord($bytes[0]) > 0x7F ? "\x00" : '').$bytes)).$bytes;
        $sequence = static fn (string $content) => "\x30".self::derLength(\strlen($content)).$content;
        $rsaPublicKey = $sequence($integer($n).$integer($e));
        $algorithm = $sequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $bitString = "\x03".self::derLength(\strlen($rsaPublicKey) + 1)."\x00".$rsaPublicKey;
        $der = $sequence($algorithm.$bitString);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /** PKCE (RFC 7636) S256 challenge of a verifier. */
    public static function pkceChallenge(string $verifier): string
    {
        return self::base64UrlEncode(hash('sha256', $verifier, true));
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return \chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return \chr(0x80 | \strlen($bytes)).$bytes;
    }
}
