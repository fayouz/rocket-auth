<?php

namespace App\Ldap;

final readonly class DirectoryUser
{
    public function __construct(
        public string $dn,
        public string $email,
        public ?string $firstName = null,
        public ?string $lastName = null,
        /** null when the directory does not manage the admin role (no LDAP_ADMIN_GROUP_DN). */
        public ?bool $admin = null,
    ) {
    }
}
