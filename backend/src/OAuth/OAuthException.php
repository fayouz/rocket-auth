<?php

namespace App\OAuth;

/** An OAuth 2.0 error (RFC 6749 §5.2 / §4.1.2.1): "error" code and human-readable description. */
final class OAuthException extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $status = 400,
    ) {
        parent::__construct($description);
    }

    /** @return array{error: string, error_description: string} */
    public function toArray(): array
    {
        return ['error' => $this->error, 'error_description' => $this->getMessage()];
    }
}
