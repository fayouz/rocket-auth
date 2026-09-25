<?php

namespace App\OAuth;

use App\Entity\OAuthClient;

/** A validated authorization request (GET /oauth/authorize parameters). */
final readonly class AuthorizationRequest
{
    /** @param list<string> $scopes */
    public function __construct(
        public OAuthClient $client,
        public string $redirectUri,
        public array $scopes,
        public ?string $state,
        public ?string $nonce,
        public ?string $codeChallenge,
        public ?string $prompt,
    ) {
    }

    /** Where to send the browser back, with the given parameters (and the state). */
    public function redirect(array $parameters): string
    {
        if (null !== $this->state && '' !== $this->state) {
            $parameters['state'] = $this->state;
        }

        return $this->redirectUri.(str_contains($this->redirectUri, '?') ? '&' : '?').http_build_query($parameters, '', '&', \PHP_QUERY_RFC3986);
    }
}
