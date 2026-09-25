<?php

namespace App\Ldap;

use Symfony\Component\Validator\Constraints as Assert;

/** Submitted LDAP settings; omitted fields keep their current value, an empty password keeps the stored one. */
final readonly class LdapConfigInput
{
    /** @param array{email?: string, firstName?: string, lastName?: string, groups?: string}|null $attributes */
    public function __construct(
        public ?bool $enabled = null,
        #[Assert\Length(max: 500)]
        public ?string $url = null,
        public ?bool $startTls = null,
        #[Assert\Length(max: 500)]
        public ?string $baseDn = null,
        #[Assert\Length(max: 500)]
        public ?string $bindDn = null,
        #[Assert\Length(max: 1000)]
        public ?string $bindPassword = null,
        #[Assert\Length(max: 1000)]
        public ?string $userFilter = null,
        #[Assert\Length(max: 500)]
        public ?string $adminGroupDn = null,
        public ?array $attributes = null,
    ) {
    }

    /** @return array<string, mixed> the submitted fields only */
    public function toArray(): array
    {
        return array_filter(get_object_vars($this), static fn ($value) => null !== $value);
    }
}
