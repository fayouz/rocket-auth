<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\AuthenticationServer;
use App\Security\SecretBox;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts the OpenID Connect client secret before saving (an empty value keeps the current one).
 *
 * @implements ProcessorInterface<AuthenticationServer, AuthenticationServer>
 */
final class AuthenticationServerProcessor implements ProcessorInterface
{
    /** @param ProcessorInterface<AuthenticationServer, AuthenticationServer> $persist */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        private readonly SecretBox $secrets,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof AuthenticationServer && null !== $data->getClientSecret() && '' !== $data->getClientSecret()) {
            $data->setEncryptedClientSecret($this->secrets->encrypt($data->getClientSecret()));
            $data->setClientSecret(null);
        }

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
