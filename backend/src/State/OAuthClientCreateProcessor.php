<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\OAuthClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * New OAuth client: a client ID derived from its name when none is given, and a secret for confidential clients
 * (returned once in the response, as plainSecret).
 *
 * @implements ProcessorInterface<OAuthClient, OAuthClient>
 */
final class OAuthClientCreateProcessor implements ProcessorInterface
{
    /** @param ProcessorInterface<OAuthClient, OAuthClient> $persist */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof OAuthClient) {
            if ('' === $data->getClientId()) {
                $slug = strtolower((string) (new AsciiSlugger())->slug($data->getName()));
                $data->setClientId(substr('' === $slug ? 'client' : $slug, 0, 60).'-'.bin2hex(random_bytes(4)));
            }
            if ($data->isConfidential()) {
                $data->rotateSecret();
            }
        }

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
