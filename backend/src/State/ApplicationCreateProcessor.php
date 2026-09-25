<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Application;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Generates the application secret on creation; the plain value is only returned in this response.
 *
 * @implements ProcessorInterface<Application, Application>
 */
final class ApplicationCreateProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Application
    {
        $data->rotateToken();

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
