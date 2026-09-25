<?php

namespace App\OAuth;

/**
 * An invalid authorization request. With a request (client and redirect URI valid), the error is sent back to the
 * application; without, it is shown to the user.
 */
final class AuthorizationError extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly ?AuthorizationRequest $request = null,
    ) {
        parent::__construct($description);
    }
}
