<?php

namespace App\EventListener;

use App\OAuth\BackchannelLogout;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Rocket\Core\Entity\User;

/**
 * An account disabled (by an administrator, or by the directory synchronization) or deleted: its sign-ins end in every
 * application (BackchannelLogout::user). The applications are looked up before the flush (a deletion removes the
 * records), the logout tokens are sent after it.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class AccountDeactivationListener
{
    /** @var array<string, list<string>> user ID => client IDs */
    private array $pending = [];

    public function __construct(private readonly BackchannelLogout $logout)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof User && [true, false] === ($uow->getEntityChangeSet($entity)['enabled'] ?? null)) {
                $this->collect($entity);
            }
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof User) {
                $this->collect($entity);
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $userId => $clientIds) {
            $this->logout->user((string) $userId, $clientIds);
        }
    }

    private function collect(User $user): void
    {
        $id = (string) $user->getId();
        $this->pending[$id] = $this->logout->clientIds($id, null);
    }
}
