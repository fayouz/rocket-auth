<?php

namespace App\Authentication;

use App\Enum\AuthenticationServerType;
use App\Ldap\LdapConfig;
use App\Ldap\LdapSettings;
use App\Ldap\UserDirectoryInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Selects the connector for the configured authentication server type. */
#[AsAlias(UserDirectoryInterface::class)]
final class AuthenticationConnectorRegistry implements UserDirectoryInterface
{
    /** @param iterable<AuthenticationConnectorInterface> $connectors */
    public function __construct(
        #[AutowireIterator('app.authentication_connector')]
        private readonly iterable $connectors,
        private readonly LdapSettings $settings,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->connector()->isEnabled();
    }

    public function checkCredentials(string $dn, string $password): bool
    {
        return $this->connector()->checkCredentials($dn, $password);
    }

    public function fetchUsers(): iterable
    {
        yield from $this->connector()->fetchUsers();
    }

    public function probe(LdapConfig $config, int $limit = 5): array
    {
        return $this->connector()->probe($config, $limit);
    }

    public function ping(LdapConfig $config): void
    {
        $this->connector()->ping($config);
    }

    private function connector(): AuthenticationConnectorInterface
    {
        $type = $this->settings->getServerType();
        foreach ($this->connectors as $connector) {
            if ($connector->supports($type)) {
                return $connector;
            }
        }

        throw new \LogicException(sprintf('No authentication connector is registered for type "%s".', $type->value));
    }
}
