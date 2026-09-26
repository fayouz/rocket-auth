<?php

namespace App\OAuth;

use App\Entity\AuthorizationCode;
use App\Entity\OAuthClient;
use App\Entity\RefreshToken;
use App\Message\SendBackchannelLogout;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Ends the sign-ins of a user in the applications:
 * - signing out of Rocket Auth: the sign-ins made during that session (sid), every one when the session has no sid;
 * - account disabled or deleted: every sign-in.
 * Refresh tokens are revoked here; the applications that declared a back-channel logout URI get a logout token.
 */
class BackchannelLogout
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
    ) {
    }

    /** The user signed out of the Rocket Auth session $sid (null: unknown session, all of them). */
    public function session(string $userId, ?string $sid): void
    {
        $this->revokeRefreshTokens($userId, $sid);
        $this->notify($this->clientIds($userId, $sid), $userId, $sid);
    }

    /**
     * The account is disabled or deleted: every sign-in ends.
     *
     * @param list<string>|null $clientIds computed before a deletion (which removes the records), else now
     */
    public function user(string $userId, ?array $clientIds = null): void
    {
        $this->revokeRefreshTokens($userId, null);
        $this->notify($clientIds ?? $this->clientIds($userId, null), $userId, null);
    }

    /**
     * The applications (client IDs) the user signed in to, in this session or in any, that receive logout tokens.
     *
     * @return list<string>
     */
    public function clientIds(string $userId, ?string $sid): array
    {
        $ids = [];
        foreach ([AuthorizationCode::class, RefreshToken::class] as $entity) {
            $qb = $this->em->createQueryBuilder()
                ->select('DISTINCT c.clientId')
                ->from($entity, 't')
                ->join('t.client', 'c')
                ->where('IDENTITY(t.user) = :user')
                ->andWhere('c.backchannelLogoutUri IS NOT NULL')
                ->andWhere('c.enabled = true')
                ->setParameter('user', Uuid::fromString($userId), 'uuid');
            if (null !== $sid) {
                $qb->andWhere('t.sid = :sid')->setParameter('sid', $sid);
            }
            array_push($ids, ...array_column($qb->getQuery()->getScalarResult(), 'clientId'));
        }

        return array_values(array_unique($ids));
    }

    /** @param list<string> $clientIds */
    public function notify(array $clientIds, string $userId, ?string $sid): void
    {
        foreach ($clientIds as $clientId) {
            $this->bus->dispatch(new SendBackchannelLogout($clientId, $userId, $sid));
        }
    }

    private function revokeRefreshTokens(string $userId, ?string $sid): void
    {
        $qb = $this->em->createQueryBuilder()
            ->update(RefreshToken::class, 't')
            ->set('t.revokedAt', ':now')
            ->where('IDENTITY(t.user) = :user')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('now', $this->clock->now(), 'datetime_immutable')
            ->setParameter('user', Uuid::fromString($userId), 'uuid');
        if (null !== $sid) {
            $qb->andWhere('t.sid = :sid')->setParameter('sid', $sid);
        }
        $qb->getQuery()->execute();
    }
}
