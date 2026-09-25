<?php

namespace App\Update;

use Symfony\Component\Validator\Constraints as Assert;

/** PUT /api/system/update/method: null goes back to the default method (UPDATE_METHOD). */
final readonly class UpdateMethodInput
{
    public function __construct(
        #[Assert\Choice(choices: ['docker', 'script', 'manual'])]
        public ?string $method = null,
    ) {
    }
}
