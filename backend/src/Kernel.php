<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }

    public function process(ContainerBuilder $container): void
    {
        // API Platform registers its test client as soon as symfony/http-client is installed (the mailer
        // bridges need it), but that client lives in api-platform/test, which does not support PHPUnit 13.
        // Functional tests use WebTestCase: drop the unusable definition.
        if (!class_exists(\ApiPlatform\Test\Client::class)) {
            $container->removeDefinition('test.api_platform.client');
        }
    }
}
