<?php

namespace App\Ldap;

interface UserDirectoryInterface
{
    public function isEnabled(): bool;

    public function checkCredentials(string $dn, string $password): bool;

    /** @return iterable<DirectoryUser> */
    public function fetchUsers(): iterable;

    /**
     * Tries a configuration (saved or not): service account bind, then a search.
     *
     * @return array{count: int, sample: list<DirectoryUser>}
     */
    public function probe(LdapConfig $config, int $limit = 5): array;

    /**
     * Health check: service account bind and a read of the base DN, with a short network timeout.
     *
     * @throws \Throwable when the server cannot be reached or refuses the bind
     */
    public function ping(LdapConfig $config): void;
}
