<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Consent;
use Rocket\Core\Security\ActorContext;
use Doctrine\ORM\QueryBuilder;

/** Everyone, administrators included, only lists their own consents. */
final class OwnConsentsExtension implements QueryCollectionExtensionInterface
{
    public function __construct(private readonly ActorContext $actor)
    {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (Consent::class !== $resourceClass) {
            return;
        }
        $alias = $queryBuilder->getRootAliases()[0];
        $user = $this->actor->getUser();
        if (null === $user) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }
        $param = $queryNameGenerator->generateParameterName('user');
        $queryBuilder->andWhere(\sprintf('%s.user = :%s', $alias, $param))->setParameter($param, $user->getId(), 'uuid');
    }
}
