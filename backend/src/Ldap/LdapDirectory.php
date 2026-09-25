<?php

namespace App\Ldap;

use App\Authentication\AuthenticationConnectorInterface;
use App\Enum\AuthenticationServerType;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Exception\InvalidCredentialsException;
use Symfony\Component\Ldap\Ldap;
use Symfony\Component\Ldap\LdapInterface;

/**
 * The LDAP directory, configured in AuthenticationServer (LdapSettings): read at each use, so a change in the
 * administration applies at once.
 */
final class LdapDirectory implements AuthenticationConnectorInterface
{
    /** Seconds allowed to the health check (connection and search). */
    private const PING_TIMEOUT = 5;

    public function __construct(private readonly LdapSettings $settings)
    {
    }

    public function supports(AuthenticationServerType $type): bool
    {
        return AuthenticationServerType::Ldap === $type;
    }

    public function isEnabled(): bool
    {
        return $this->settings->get()->enabled;
    }

    public function checkCredentials(string $dn, string $password): bool
    {
        $config = $this->settings->get();
        // An empty password would perform an anonymous bind, which most servers accept.
        if (!$config->enabled || '' === $dn || '' === $password) {
            return false;
        }

        try {
            $this->connect($config)->bind($dn, $password);

            return true;
        } catch (InvalidCredentialsException) {
            return false;
        }
    }

    public function fetchUsers(): iterable
    {
        $config = $this->settings->get();
        if (!$config->enabled) {
            return;
        }

        yield from $this->search($config);
    }

    public function probe(LdapConfig $config, int $limit = 5): array
    {
        $count = 0;
        $sample = [];
        foreach ($this->search($config) as $user) {
            ++$count;
            if (\count($sample) < $limit) {
                $sample[] = $user;
            }
        }

        return ['count' => $count, 'sample' => $sample];
    }

    public function ping(LdapConfig $config): void
    {
        $ldap = $this->connect($config, networkTimeout: self::PING_TIMEOUT);
        $ldap->bind('' === $config->bindDn ? null : $config->bindDn, '' === $config->bindPassword ? null : $config->bindPassword);
        $ldap->query($config->baseDn, '(objectClass=*)', ['scope' => 'base', 'sizeLimit' => 1, 'timeout' => self::PING_TIMEOUT])->execute()->count();
    }

    /** @return iterable<DirectoryUser> */
    private function search(LdapConfig $config): iterable
    {
        $ldap = $this->connect($config);
        $ldap->bind('' === $config->bindDn ? null : $config->bindDn, '' === $config->bindPassword ? null : $config->bindPassword);
        $attributes = $config->attributes;
        $query = $ldap->query($config->baseDn, $config->userFilter ?: '(objectClass=*)', [
            'filter' => array_values(array_unique($attributes)),
        ]);

        foreach ($query->execute() as $entry) {
            $email = $this->first($entry, $attributes['email']);
            if (null === $email) {
                continue;
            }

            yield new DirectoryUser(
                dn: $entry->getDn(),
                email: $email,
                firstName: $this->first($entry, $attributes['firstName']),
                lastName: $this->first($entry, $attributes['lastName']),
                admin: '' === $config->adminGroupDn ? null : \in_array(
                    mb_strtolower($config->adminGroupDn),
                    array_map('mb_strtolower', $this->all($entry, $attributes['groups'])),
                    true,
                ),
            );
        }
    }

    private function connect(LdapConfig $config, ?int $networkTimeout = null): LdapInterface
    {
        $options = ['connection_string' => $config->url];
        if (null !== $networkTimeout) {
            $options['options'] = ['network_timeout' => $networkTimeout];
        }
        if ($config->startTls) {
            $options['encryption'] = 'tls';
        }

        return Ldap::create('ext_ldap', $options);
    }

    private function first(Entry $entry, string $attribute): ?string
    {
        $value = $this->all($entry, $attribute)[0] ?? null;

        return null === $value || '' === $value ? null : (string) $value;
    }

    /** @return list<string> Attribute names are case-insensitive in LDAP. */
    private function all(Entry $entry, string $attribute): array
    {
        foreach ($entry->getAttributes() as $name => $values) {
            if (0 === strcasecmp($name, $attribute)) {
                return array_values(array_map('strval', $values));
            }
        }

        return [];
    }
}
