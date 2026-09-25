<?php

namespace App\Setup;

use Symfony\Component\Validator\Constraints as Assert;

/** First administrator, created by the first-run setup. */
final class SetupInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 180)]
        public readonly string $email = '',
        #[Assert\NotBlank]
        #[Assert\Length(min: 12, max: 4096, minMessage: 'The password must be at least {{ limit }} characters long.')]
        public readonly string $password = '',
        #[Assert\Length(max: 100)]
        public readonly ?string $firstName = null,
        #[Assert\Length(max: 100)]
        public readonly ?string $lastName = null,
        /** Required when SETUP_TOKEN is set. */
        public readonly ?string $setupToken = null,
    ) {
    }
}
