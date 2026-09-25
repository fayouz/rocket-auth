<?php

namespace App\Oidc;

use Symfony\Component\Validator\Constraints as Assert;

/** POST /api/authentication_servers/oidc/test: checks an issuer before saving it. */
final readonly class OidcTestInput
{
    public function __construct(
        #[Assert\NotBlank] #[Assert\Url(requireTld: false)]
        public string $url = '',
        #[Assert\Url(requireTld: false)]
        public string $internalUrl = '',
    ) {
    }
}
