<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\AuthenticationServer;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/** @implements ProcessorInterface<AuthenticationServer, null> */
final class AuthenticationServerDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof AuthenticationServer) {
            return null;
        }

        $request = $context['request'] ?? null;
        $disableUsers = $request instanceof Request && $this->disableUsers($request);
        if ($disableUsers) {
            $this->users->disableUsersForAuthenticationServer($data);
        }

        $this->em->remove($data);
        $this->em->flush();

        return null;
    }

    private function disableUsers(Request $request): bool
    {
        try {
            return true === $request->toArray()['disableUsers'];
        } catch (\Throwable) {
            return false;
        }
    }
}
