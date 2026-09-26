<?php

namespace App\Controller;

use App\OAuth\AuthorizationError;
use App\OAuth\AuthorizationServer;
use App\OAuth\Scopes;
use App\Repository\OAuthClientRepository;
use Rocket\Core\Security\ActorContext;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Backs the interface pages of the provider: the authorization (consent) page and the sign-out page.
 * The parameters are those the application sent to /oauth/authorize (or /oauth/logout).
 */
final class AuthorizeController extends AbstractController
{
    public function __construct(
        private readonly AuthorizationServer $server,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Signed-in user: what the application asks for, and whether the user must consent. */
    #[Route('/api/oauth/authorize', name: 'api_oauth_authorize_check', methods: ['GET'])]
    public function check(Request $request, ActorContext $actor): JsonResponse
    {
        try {
            $authorization = $this->server->validateAuthorization($request->query->all());
        } catch (AuthorizationError $e) {
            return $this->failure($e);
        }
        $user = $actor->requireUser();
        $consentRequired = $this->server->needsConsent($authorization, $user);
        if ($consentRequired && null !== $authorization->prompt && str_contains($authorization->prompt, 'none')) {
            return $this->json(['redirectUrl' => $this->server->deny($authorization, $user, 'consent_required', 'The user must consent.')]);
        }

        return $this->json([
            'client' => [
                'id' => $authorization->client->getClientId(),
                'name' => $authorization->client->getName(),
                'description' => $authorization->client->getDescription(),
                'trusted' => $authorization->client->isTrusted(),
                'redirectHost' => parse_url($authorization->redirectUri, \PHP_URL_HOST),
            ],
            'scopes' => array_map(static fn (string $scope) => ['name' => $scope, 'description' => Scopes::DESCRIPTIONS[$scope] ?? $scope], $authorization->scopes),
            'consentRequired' => $consentRequired,
        ]);
    }

    /** Signed-in user approves (or refuses): the browser is sent back to the application. */
    #[Route('/api/oauth/authorize', name: 'api_oauth_authorize', methods: ['POST'])]
    public function decide(Request $request, ActorContext $actor, Security $security, JWTTokenManagerInterface $jwt): JsonResponse
    {
        $payload = $request->toArray();
        try {
            $authorization = $this->server->validateAuthorization(\is_array($payload['params'] ?? null) ? $payload['params'] : []);
        } catch (AuthorizationError $e) {
            return $this->failure($e);
        }
        $user = $actor->requireUser();
        if (true !== ($payload['approve'] ?? false)) {
            return $this->json(['redirectUrl' => $this->server->deny($authorization, $user)]);
        }

        // auth_time: when the Rocket Auth session started; sid: that session (see SessionIdListener).
        $authTime = $this->clock->now();
        $sid = null;
        try {
            $token = $security->getToken();
            $claims = null === $token ? null : $jwt->decode($token);
            if (\is_array($claims) && \is_int($claims['iat'] ?? null)) {
                $authTime = (new \DateTimeImmutable())->setTimestamp($claims['iat']);
            }
            if (\is_array($claims) && \is_string($claims['sid'] ?? null) && '' !== $claims['sid']) {
                $sid = $claims['sid'];
            }
        } catch (\Throwable) {
        }

        return $this->json(['redirectUrl' => $this->server->approve($authorization, $user, $authTime, $sid)]);
    }

    /** Public: sends the browser back with an error (not signed in with prompt=none, sign-in cancelled…). */
    #[Route('/api/oauth/authorize/cancel', name: 'api_oauth_authorize_cancel', methods: ['POST'])]
    public function cancel(Request $request): JsonResponse
    {
        $payload = $request->toArray();
        try {
            $authorization = $this->server->validateAuthorization(\is_array($payload['params'] ?? null) ? $payload['params'] : []);
        } catch (AuthorizationError $e) {
            return $this->failure($e);
        }
        $error = \in_array($payload['error'] ?? null, ['login_required', 'access_denied'], true) ? $payload['error'] : 'access_denied';

        return $this->json(['redirectUrl' => $this->server->deny($authorization, null, $error, 'login_required' === $error ? 'The user is not signed in.' : 'The user cancelled the sign-in.')]);
    }

    /**
     * Public: where to send the browser after signing out (RP-initiated logout). Only a URI registered for the
     * application is followed; the application is identified by client_id or by its ID token (id_token_hint).
     */
    #[Route('/api/oauth/logout', name: 'api_oauth_logout', methods: ['GET'])]
    public function logout(Request $request, OAuthClientRepository $clients): JsonResponse
    {
        $clientId = $request->query->getString('client_id');
        if ('' === $clientId && '' !== $hint = $request->query->getString('id_token_hint')) {
            try {
                [, $claims] = \Rocket\Core\Oidc\Jwt::decode($hint);
                $clientId = \is_string($claims['aud'] ?? null) ? $claims['aud'] : '';
            } catch (\Throwable) {
            }
        }
        $client = '' === $clientId ? null : $clients->findOneBy(['clientId' => $clientId]);
        $uri = $request->query->getString('post_logout_redirect_uri');
        if (null === $client || '' === $uri || !\in_array($uri, $client->getPostLogoutRedirectUris(), true)) {
            return $this->json(['redirectUrl' => null, 'client' => $client?->getName()]);
        }
        $state = $request->query->getString('state');

        return $this->json([
            'redirectUrl' => $uri.('' === $state ? '' : (str_contains($uri, '?') ? '&' : '?').http_build_query(['state' => $state])),
            'client' => $client->getName(),
        ]);
    }

    private function failure(AuthorizationError $e): JsonResponse
    {
        if (null !== $e->request) {
            return $this->json(['redirectUrl' => $e->request->redirect(['error' => $e->error, 'error_description' => $e->getMessage()])]);
        }

        return $this->json(['error' => $e->error, 'detail' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
    }
}
