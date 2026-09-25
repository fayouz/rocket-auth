<?php

namespace App\Ldap;

/**
 * LDAP directory settings, mapped from the AuthenticationServer entity (see LdapSettings).
 */
final readonly class LdapConfig
{
    public const DEFAULT_ATTRIBUTES = ['email' => 'mail', 'firstName' => 'givenName', 'lastName' => 'sn', 'groups' => 'memberOf'];

    /** @param array{email: string, firstName: string, lastName: string, groups: string} $attributes */
    public function __construct(
        public bool $enabled = false,
        /** ldap://host:389 or ldaps://host:636 */
        public string $url = 'ldap://localhost:389',
        /** STARTTLS on an ldap:// connection. */
        public bool $startTls = false,
        public string $baseDn = '',
        /** Service account used to search the directory. */
        public string $bindDn = '',
        #[\SensitiveParameter] public string $bindPassword = '',
        public string $userFilter = '(objectClass=inetOrgPerson)',
        /** Members of this group are administrators; empty: roles are managed in Rocket Auth. */
        public string $adminGroupDn = '',
        public array $attributes = self::DEFAULT_ATTRIBUTES,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?self $defaults = null): self
    {
        $defaults ??= new self();
        $attributes = \is_array($data['attributes'] ?? null) ? $data['attributes'] : [];

        return new self(
            enabled: (bool) ($data['enabled'] ?? $defaults->enabled),
            url: trim((string) ($data['url'] ?? $defaults->url)),
            startTls: (bool) ($data['startTls'] ?? $defaults->startTls),
            baseDn: trim((string) ($data['baseDn'] ?? $defaults->baseDn)),
            bindDn: trim((string) ($data['bindDn'] ?? $defaults->bindDn)),
            bindPassword: (string) ($data['bindPassword'] ?? $defaults->bindPassword),
            userFilter: trim((string) ($data['userFilter'] ?? $defaults->userFilter)),
            adminGroupDn: trim((string) ($data['adminGroupDn'] ?? $defaults->adminGroupDn)),
            attributes: array_map(
                static fn (string $key) => trim((string) ($attributes[$key] ?? '')) ?: $defaults->attributes[$key],
                array_combine(array_keys(self::DEFAULT_ATTRIBUTES), array_keys(self::DEFAULT_ATTRIBUTES)),
            ),
        );
    }

    /** @return list<string> problems preventing the use of the directory */
    public function errors(): array
    {
        $errors = [];
        if (!preg_match('#^ldaps?://[^/\s]+#i', $this->url)) {
            $errors[] = 'url: expected ldap://host:389 or ldaps://host:636.';
        }
        if ($this->startTls && str_starts_with(mb_strtolower($this->url), 'ldaps://')) {
            $errors[] = 'startTls: STARTTLS only applies to ldap:// (ldaps:// is already encrypted).';
        }
        if ('' === $this->baseDn) {
            $errors[] = 'baseDn: the search base is required.';
        }
        if ('' !== $this->userFilter && !preg_match('/^\(.*\)$/s', $this->userFilter)) {
            $errors[] = 'userFilter: an LDAP filter is enclosed in parentheses, e.g. (objectClass=inetOrgPerson).';
        }

        return $errors;
    }

    /** @return array<string, mixed> everything but the password */
    public function toPublicArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'url' => $this->url,
            'startTls' => $this->startTls,
            'baseDn' => $this->baseDn,
            'bindDn' => $this->bindDn,
            'hasBindPassword' => '' !== $this->bindPassword,
            'userFilter' => $this->userFilter,
            'adminGroupDn' => $this->adminGroupDn,
            'attributes' => $this->attributes,
        ];
    }
}
