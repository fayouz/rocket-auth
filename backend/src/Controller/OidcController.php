<?php

namespace App\Controller;

use App\Enum\AuthenticationServerType;
use App\Oidc\OidcAccountLinker;
use App\Oidc\OidcCallbackInput;
use App\Oidc\OidcClient;
use App\Oidc\OidcException;
use App\Oidc\OidcTestInput;
use App\Repository\AuthenticationServerRepository;
use App\Security\Roles;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Sign-in through OpenID Connect providers ("authentication servers" of type oidc). */
final class OidcController extends AbstractController
{
    public function __construct(
        private readonly AuthenticationServerRepository $servers,
        private readonly OidcClient $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Public: the providers offered on the login page, with what the browser needs to start the flow. */
    #[Route('/api/auth/providers', name: 'api_auth_providers', methods: ['GET'])]
    public function providers(): JsonResponse
    {
        $providers = [];
        foreach ($this->servers->findEnabledOidc() as $server) {
            try {
                $metadata = $this->client->discover($server);
            } catch (OidcException $e) {
                $this->logger->warning('OpenID Connect provider {name} unavailable: {message}', ['name' => $server->getName(), 'message' => $e->getMessage()]);
                continue;
            }
            $providers[] = [
                'id' => (string) $server->getId(),
                'name' => $server->getName(),
                'authorizationEndpoint' => $metadata['authorization_endpoint'],
                'endSessionEndpoint' => \is_string($metadata['end_session_endpoint'] ?? null) ? $metadata['end_session_endpoint'] : null,
                'clientId' => $server->getClientId(),
                'scope' => $server->getScopes(),
            ];
        }

        return $this->json(['providers' => $providers]);
    }

    /** Public: completes the sign-in and opens a session (same response as /api/auth/login). */
    #[Route('/api/auth/oidc/callback', name: 'api_auth_oidc_callback', methods: ['POST'])]
    public function callback(#[MapRequestPayload] OidcCallbackInput $input, OidcAccountLinker $linker, JWTTokenManagerInterface $tokens): JsonResponse
    {
        $server = $this->servers->find($input->provider);
        if (null === $server || AuthenticationServerType::Oidc !== $server->getType() || !$server->isEnabled()) {
            return $this->json(['code' => 401, 'message' => 'Unknown or disabled authentication server.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $claims = $this->client->authenticate($server, $input->code, $input->codeVerifier, $input->redirectUri, $input->nonce);
            $user = $linker->resolve($server, $claims);
        } catch (OidcException $e) {
            return $this->json(['code' => 401, 'message' => $e->getMessage()], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(['token' => $tokens->create($user), 'provider' => $server->getName()]);
    }

    /** Admin: reads the discovery document of an issuer, to check a configuration before saving it. */
    #[Route('/api/authentication_servers/oidc/test', name: 'api_authentication_servers_oidc_test', methods: ['POST'], priority: 10)]
    #[IsGranted(Roles::ADMIN)]
    public function test(#[MapRequestPayload] OidcTestInput $input): JsonResponse
    {
        try {
            $metadata = $this->client->fetchDiscovery($input->url, $input->internalUrl);
        } catch (OidcException $e) {
            return $this->json(['ok' => false, 'message' => $e->getMessage()]);
        }

        return $this->json([
            'ok' => true,
            'message' => sprintf('Fournisseur OpenID Connect trouvé : %s.', $metadata['issuer']),
            'issuer' => $metadata['issuer'],
            'authorizationEndpoint' => $metadata['authorization_endpoint'],
            'scopesSupported' => $metadata['scopes_supported'] ?? [],
        ]);
    }
}
