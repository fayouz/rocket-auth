<?php

namespace App\Controller;

use App\Ldap\DirectoryUser;
use App\Ldap\LdapConfig;
use App\Ldap\LdapConfigInput;
use App\Ldap\LdapSettings;
use App\Ldap\UserDirectoryInterface;
use App\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** LDAP directory settings, managed by administrators (the bind password is never returned). */
#[IsGranted(Roles::ADMIN)]
final class LdapConfigController extends AbstractController
{
    public function __construct(private readonly LdapSettings $settings)
    {
    }

    #[Route('/api/ldap/config', name: 'api_ldap_config', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return $this->json($this->payload());
    }

    #[Route('/api/ldap/config', name: 'api_ldap_config_update', methods: ['PUT'])]
    public function update(#[MapRequestPayload] LdapConfigInput $input): JsonResponse
    {
        $config = $this->settings->merge($input->toArray());
        if ($config->enabled && [] !== $errors = $config->errors()) {
            throw new UnprocessableEntityHttpException(implode("\n", $errors));
        }
        $this->settings->save($config);

        return $this->json($this->payload());
    }

    /** Back to the default configuration of the .env (LDAP_* variables). */
    #[Route('/api/ldap/config', name: 'api_ldap_config_reset', methods: ['DELETE'])]
    public function reset(): JsonResponse
    {
        $this->settings->resetToDefaults();

        return $this->json($this->payload());
    }

    /** Tries the submitted settings (not saved): bind with the service account, then search the users. */
    #[Route('/api/ldap/test', name: 'api_ldap_test', methods: ['POST'])]
    public function test(UserDirectoryInterface $directory, #[MapRequestPayload] ?LdapConfigInput $input = null): JsonResponse
    {
        $config = $this->settings->merge($input?->toArray() ?? []);
        if ([] !== $errors = $config->errors()) {
            return $this->json(['ok' => false, 'message' => implode("\n", $errors), 'count' => 0, 'sample' => []]);
        }

        try {
            $result = $directory->probe($config);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'message' => $e->getMessage(), 'count' => 0, 'sample' => []]);
        }

        return $this->json([
            'ok' => true,
            'message' => \sprintf('%d utilisateur(s) trouvé(s) avec une adresse email.', $result['count']),
            'count' => $result['count'],
            'sample' => array_map(static fn (DirectoryUser $u) => [
                'dn' => $u->dn, 'email' => $u->email, 'firstName' => $u->firstName, 'lastName' => $u->lastName, 'admin' => $u->admin,
            ], $result['sample']),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return $this->settings->get()->toPublicArray() + [
            'source' => $this->settings->isStored() ? 'database' : 'environment',
            'defaults' => ['attributes' => LdapConfig::DEFAULT_ATTRIBUTES],
        ];
    }
}
