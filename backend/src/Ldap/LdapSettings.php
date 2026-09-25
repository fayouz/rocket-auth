<?php

namespace App\Ldap;

use App\Entity\AuthenticationServer;
use App\Enum\AuthenticationServerType;
use App\Security\SecretBox;
use App\Repository\AuthenticationServerRepository;
use App\Settings\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The LDAP configuration, stored in AuthenticationServer and managed by administrators (bind password encrypted).
 * The LDAP_* environment variables (.env) are the default configuration: used until it is saved once,
 * and again after resetToDefaults().
 */
class LdapSettings implements ResetInterface
{
    public const KEY = 'ldap';

    private ?LdapConfig $cached = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly SecretBox $secrets,
        private readonly EntityManagerInterface $em,
        private readonly AuthenticationServerRepository $servers,
        #[Autowire(env: 'bool:LDAP_ENABLED')] private readonly bool $envEnabled,
        #[Autowire(env: 'LDAP_URL')] private readonly string $envUrl,
        #[Autowire(env: 'LDAP_BASE_DN')] private readonly string $envBaseDn,
        #[Autowire(env: 'LDAP_SEARCH_DN')] private readonly string $envBindDn,
        #[Autowire(env: 'LDAP_SEARCH_PASSWORD')] #[\SensitiveParameter] private readonly string $envBindPassword,
        #[Autowire(env: 'LDAP_USER_FILTER')] private readonly string $envUserFilter,
        #[Autowire(env: 'LDAP_ADMIN_GROUP_DN')] private readonly string $envAdminGroupDn,
        #[Autowire(env: 'bool:LDAP_START_TLS')] private readonly bool $envStartTls = false,
        /** @var array{email: string, firstName: string, lastName: string, groups: string} */
        #[Autowire([
            'email' => '%env(LDAP_ATTRIBUTE_EMAIL)%',
            'firstName' => '%env(LDAP_ATTRIBUTE_FIRST_NAME)%',
            'lastName' => '%env(LDAP_ATTRIBUTE_LAST_NAME)%',
            'groups' => '%env(LDAP_ATTRIBUTE_GROUPS)%',
        ])]
        private readonly array $envAttributes = LdapConfig::DEFAULT_ATTRIBUTES,
    ) {
    }

    public function get(): LdapConfig
    {
        if (null !== $this->cached) {
            return $this->cached;
        }

        if (null !== $server = $this->servers->findLdap()) {
            return $this->cached = $this->fromEntity($server);
        }

        $stored = $this->settings->get(self::KEY);
        if (!\is_array($stored)) {
            return $this->cached = $this->fromEnvironment();
        }
        if (isset($stored['bindPassword']) && '' !== $stored['bindPassword']) {
            $stored['bindPassword'] = $this->secrets->decrypt($stored['bindPassword']);
        }

        return $this->cached = LdapConfig::fromArray($stored);
    }

    /** Saved from the administration at least once (else: environment variables). */
    public function isStored(): bool
    {
        return null !== $this->servers->findLdap() || \is_array($this->settings->get(self::KEY));
    }

    public function getServerType(): AuthenticationServerType
    {
        return $this->servers->findLdap()?->getType() ?? AuthenticationServerType::Ldap;
    }

    /**
     * Builds a configuration from submitted values; an empty password keeps the current one.
     *
     * @param array<string, mixed> $data
     */
    public function merge(array $data): LdapConfig
    {
        $current = $this->get();
        if (!isset($data['bindPassword']) || '' === $data['bindPassword']) {
            $data['bindPassword'] = $current->bindPassword;
        }

        return LdapConfig::fromArray($data, $current);
    }

    /** Forgets the saved configuration: back to the LDAP_* environment variables. */
    public function resetToDefaults(): void
    {
        if (null !== $server = $this->servers->findLdap()) {
            $this->em->remove($server);
        }
        $this->settings->remove(self::KEY);
        $this->em->flush();
        $this->cached = null;
    }

    public function save(LdapConfig $config): void
    {
        $server = $this->servers->findLdap() ?? new AuthenticationServer();
        $server
            ->setName('LDAP')
            ->setEnabled($config->enabled)
            ->setUrl($config->url)
            ->setStartTls($config->startTls)
            ->setBaseDn($config->baseDn)
            ->setBindDn($config->bindDn)
            ->setBindPassword('' === $config->bindPassword ? '' : $this->secrets->encrypt($config->bindPassword))
            ->setUserFilter($config->userFilter)
            ->setAdminGroupDn($config->adminGroupDn)
            ->setAttributes($config->attributes);
        if (null === $server->getCreatedAt()) {
            $this->em->persist($server);
        }
        $this->settings->remove(self::KEY);
        $this->em->flush();
        $this->cached = $config;
    }

    /** Between requests (long-running workers): read the database again. */
    public function reset(): void
    {
        $this->cached = null;
    }

    private function fromEnvironment(): LdapConfig
    {
        return new LdapConfig(
            enabled: $this->envEnabled,
            url: $this->envUrl,
            baseDn: $this->envBaseDn,
            bindDn: $this->envBindDn,
            bindPassword: $this->envBindPassword,
            userFilter: $this->envUserFilter,
            adminGroupDn: $this->envAdminGroupDn,
            startTls: $this->envStartTls,
            attributes: LdapConfig::fromArray(['attributes' => $this->envAttributes])->attributes,
        );
    }

    private function fromEntity(AuthenticationServer $server): LdapConfig
    {
        $password = $server->getBindPassword();
        if ('' !== $password) {
            $password = $this->secrets->decrypt($password);
        }

        return new LdapConfig(
            enabled: $server->isEnabled(),
            url: $server->getUrl(),
            baseDn: $server->getBaseDn(),
            bindDn: $server->getBindDn(),
            bindPassword: $password,
            userFilter: $server->getUserFilter(),
            adminGroupDn: $server->getAdminGroupDn(),
            startTls: $server->hasStartTls(),
            attributes: LdapConfig::fromArray(['attributes' => $server->getAttributes()])->attributes,
        );
    }
}
