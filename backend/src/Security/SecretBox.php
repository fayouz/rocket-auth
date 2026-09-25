<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts secrets at rest (LDAP bind password, OpenID Connect client secrets…) with libsodium secretbox.
 * The key derives from SECRETS_ENCRYPTION_KEY, or APP_SECRET when it is empty: changing it makes the stored secrets unreadable (to be entered again).
 */
final class SecretBox
{
    private const PREFIX = 'v1:';

    private readonly string $key;

    public function __construct(
        #[Autowire(env: 'SECRETS_ENCRYPTION_KEY')] #[\SensitiveParameter] string $encryptionKey,
        #[Autowire(env: 'APP_SECRET')] #[\SensitiveParameter] string $appSecret,
    ) {
        $material = '' !== $encryptionKey ? $encryptionKey : $appSecret;
        $this->key = sodium_crypto_generichash('rocket/secrets|'.$material, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(#[\SensitiveParameter] string $plain): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $encrypted): string
    {
        $raw = str_starts_with($encrypted, self::PREFIX) ? base64_decode(substr($encrypted, \strlen(self::PREFIX)), true) : false;
        $plain = false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ? false : sodium_crypto_secretbox_open(
            substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );
        if (false === $plain) {
            throw new \RuntimeException('A stored secret cannot be decrypted: SECRETS_ENCRYPTION_KEY (or APP_SECRET) changed. Enter it again.');
        }

        return $plain;
    }
}
