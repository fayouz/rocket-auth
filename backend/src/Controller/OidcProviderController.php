<?php

namespace App\Controller;

use App\OAuth\OAuthException;
use App\OAuth\AuthorizationServer;
use App\OAuth\Scopes;
use App\OAuth\SigningKeys;
use App\OAuth\TokenIssuer;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public endpoints of the OpenID Connect provider (outside the /api firewall). The browser pages (authorization
 * and sign-out) live in the interface: /oauth/authorize and /oauth/logout forward to it, with the same parameters.
 */
final class OidcProviderController extends AbstractController
{
    public function __construct(
        private readonly TokenIssuer $tokens,
        #[Autowire(env: 'FRONTEND_URL')] private readonly string $frontendUrl,
    ) {
    }

    #[Route('/.well-known/openid-configuration', name: 'oidc_discovery', methods: ['GET'])]
    public function discovery(): JsonResponse
    {
        $issuer = $this->tokens->issuer();

        return $this->cors(new JsonResponse([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'userinfo_endpoint' => $issuer.'/oauth/userinfo',
            'jwks_uri' => $issuer.'/oauth/jwks',
            'revocation_endpoint' => $issuer.'/oauth/revoke',
            'end_session_endpoint' => $issuer.'/oauth/logout',
            'scopes_supported' => Scopes::ALL,
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token', 'client_credentials'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'revocation_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'code_challenge_methods_supported' => ['S256'],
            'claims_supported' => ['sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce', 'name', 'given_name', 'family_name', 'preferred_username', 'updated_at', 'email', 'email_verified', 'groups'],
            'prompt_values_supported' => ['none', 'login', 'consent', 'select_account'],
            'authorization_response_iss_parameter_supported' => true,
        ]));
    }

    #[Route('/oauth/jwks', name: 'oidc_jwks', methods: ['GET'])]
    public function jwks(SigningKeys $keys): JsonResponse
    {
        return $this->cors(new JsonResponse(['keys' => [$keys->publicJwk()]]));
    }

    /** The authorization page is part of the interface (it needs the user's session). */
    #[Route('/oauth/authorize', name: 'oidc_authorize', methods: ['GET'])]
    public function authorize(Request $request): RedirectResponse
    {
        return $this->toInterface('/authorize', $request);
    }

    #[Route('/oauth/logout', name: 'oidc_logout', methods: ['GET'])]
    public function logout(Request $request): RedirectResponse
    {
        return $this->toInterface('/logout', $request);
    }

    #[Route('/oauth/token', name: 'oidc_token', methods: ['POST', 'OPTIONS'])]
    public function token(Request $request, AuthorizationServer $server): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->cors(new JsonResponse(null, Response::HTTP_NO_CONTENT));
        }
        try {
            $response = $server->token($request->request->all(), $request->getUser(), $request->getPassword());
        } catch (OAuthException $e) {
            return $this->error($e);
        }

        return $this->cors(new JsonResponse($response, headers: ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']));
    }

    #[Route('/oauth/revoke', name: 'oidc_revoke', methods: ['POST', 'OPTIONS'])]
    public function revoke(Request $request, AuthorizationServer $server): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->cors(new JsonResponse(null, Response::HTTP_NO_CONTENT));
        }
        try {
            $server->revoke($request->request->all(), $request->getUser(), $request->getPassword());
        } catch (OAuthException $e) {
            return $this->error($e);
        }

        return $this->cors(new JsonResponse([]));
    }

    #[Route('/oauth/userinfo', name: 'oidc_userinfo', methods: ['GET', 'POST', 'OPTIONS'])]
    public function userinfo(Request $request, UserRepository $users): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->cors(new JsonResponse(null, Response::HTTP_NO_CONTENT));
        }
        $authorization = (string) $request->headers->get('Authorization');
        try {
            if (!preg_match('/^Bearer\s+(\S+)$/i', $authorization, $match)) {
                throw new OAuthException('invalid_token', 'A Bearer access token is required.', 401);
            }
            $claims = $this->tokens->verifyAccessToken($match[1]);
            $user = $users->find((string) $claims['sub']);
            if (null === $user || !$user->isEnabled()) {
                throw new OAuthException('invalid_token', 'This account no longer exists or is disabled.', 401);
            }
        } catch (OAuthException $e) {
            $response = $this->error($e);
            $response->headers->set('WWW-Authenticate', \sprintf('Bearer error="%s"', $e->error));

            return $response;
        }

        return $this->cors(new JsonResponse(Scopes::claims($user, Scopes::parse((string) ($claims['scope'] ?? '')))));
    }

    private function toInterface(string $path, Request $request): RedirectResponse
    {
        $query = $request->getQueryString();

        return new RedirectResponse(rtrim($this->frontendUrl, '/').$path.(null === $query ? '' : '?'.$query));
    }

    private function error(OAuthException $e): JsonResponse
    {
        return $this->cors(new JsonResponse($e->toArray(), $e->status, ['Cache-Control' => 'no-store']));
    }

    /** Browser applications (public clients) call these endpoints directly: any origin, no credentials. */
    private function cors(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');

        return $response;
    }
}
