<?php

namespace App\Health;

use App\Entity\ServiceCheck;
use App\Ldap\LdapSettings;
use App\Ldap\UserDirectoryInterface;
use App\Entity\AuthenticationServer;
use App\Oidc\OidcClient;
use App\Repository\AuthenticationServerRepository;
use App\Repository\ServiceCheckRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Lock\LockFactory;

/**
 * Network checks of the LDAP server, of the OpenID Connect providers (discovery document) and of the dependencies
 * declared by the domain modules (App\Health\ServiceProbeInterface).
 * Run by the scheduler every 5 minutes, or on demand from the dashboard; results kept in ServiceCheck.
 */
class HealthChecker
{
    public function __construct(
        private readonly UserDirectoryInterface $directory,
        private readonly LdapSettings $ldapSettings,
        private readonly ServiceCheckRepository $checks,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly LockFactory $locks,
        private readonly AuthenticationServerRepository $servers,
        private readonly OidcClient $oidc,
        /** @var iterable<ServiceProbeInterface> */
        #[AutowireIterator('app.service_probe')]
        private readonly iterable $probes = [],
    ) {
    }

    public static function probeCheckId(ServiceProbeInterface $probe, string $item): string
    {
        return \sprintf('%s:%s', $probe->id(), $item);
    }

    public static function oidcCheckId(AuthenticationServer $server): string
    {
        return \sprintf('oidc:%s', $server->getId());
    }

    /** @return bool false when checks are already running elsewhere */
    public function checkAll(): bool
    {
        $lock = $this->locks->createLock('app-health-checks', ttl: 600);
        if (!$lock->acquire()) {
            return false;
        }

        try {
            $existing = $this->checks->allById();
            $seen = [];

            $config = $this->ldapSettings->get();
            if ($config->enabled) {
                $start = hrtime(true);
                try {
                    $this->directory->ping($config);
                    $this->record($existing, $seen, 'ldap', true, 'Connexion et authentification réussies', $start);
                } catch (\Throwable $e) {
                    $this->record($existing, $seen, 'ldap', false, $e->getMessage(), $start);
                }
            }

            foreach ($this->servers->findEnabledOidc() as $server) {
                $start = hrtime(true);
                try {
                    $metadata = $this->oidc->discover($server, fresh: true);
                    $this->record($existing, $seen, self::oidcCheckId($server), true, 'Fournisseur joignable : '.$metadata['issuer'], $start);
                } catch (\Throwable $e) {
                    $this->record($existing, $seen, self::oidcCheckId($server), false, $e->getMessage(), $start);
                }
            }

            foreach ($this->probes as $probe) {
                foreach ($probe->targets() as $item => $target) {
                    $start = hrtime(true);
                    try {
                        $this->record($existing, $seen, self::probeCheckId($probe, $item), true, ($target['check'])(), $start);
                    } catch (\Throwable $e) {
                        $this->record($existing, $seen, self::probeCheckId($probe, $item), false, $e->getMessage(), $start);
                    }
                }
            }

            // LDAP turned off, provider disabled or deleted: forget its results.
            foreach (array_diff_key($existing, $seen) as $stale) {
                $this->em->remove($stale);
            }
            $this->em->flush();

            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<string, ServiceCheck> $existing
     * @param array<string, true>         $seen
     */
    private function record(array &$existing, array &$seen, string $id, bool $ok, string $detail, ?int $start): void
    {
        $check = $existing[$id] ??= new ServiceCheck($id);
        if (!$this->em->contains($check)) {
            $this->em->persist($check);
        }
        $check->record($ok, $detail, null === $start ? null : round((hrtime(true) - $start) / 1e6, 1), $this->clock->now());
        $seen[$id] = true;
    }
}
