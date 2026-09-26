<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Consent;
use App\OAuth\AuthorizationServer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Withdrawing a consent also revokes the application's refresh tokens: it must ask again.
 *
 * @implements ProcessorInterface<Consent, null>
 */
final class ConsentDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuthorizationServer $server,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Consent) {
            $this->server->revokeAll($data->getUser(), $data->getClient());
            $this->em->remove($data);
            $this->em->flush();
        }

        return null;
    }
}
