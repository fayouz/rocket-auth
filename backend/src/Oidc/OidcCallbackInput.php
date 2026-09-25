<?php

namespace App\Oidc;

use Symfony\Component\Validator\Constraints as Assert;

/** POST /api/auth/oidc/callback: the browser hands over the authorization response and its PKCE verifier. */
final readonly class OidcCallbackInput
{
    public function __construct(
        #[Assert\NotBlank] #[Assert\Uuid]
        public string $provider = '',
        #[Assert\NotBlank] #[Assert\Length(max: 2048)]
        public string $code = '',
        #[Assert\NotBlank] #[Assert\Length(min: 43, max: 128)]
        public string $codeVerifier = '',
        #[Assert\NotBlank] #[Assert\Url(requireTld: false)]
        public string $redirectUri = '',
        #[Assert\Length(max: 255)]
        public ?string $nonce = null,
    ) {
    }
}
