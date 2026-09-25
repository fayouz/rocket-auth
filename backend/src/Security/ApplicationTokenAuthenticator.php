<?php

namespace App\Security;

use App\Entity\Application;
use App\Entity\User;
use App\Repository\ApplicationRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Authenticates external applications with "Authorization: Bearer raa_…".
 * With "X-Impersonate-User: <email>", an application allowed to impersonate acts as that user,
 * without ever inheriting ROLE_ADMIN.
 */
final class ApplicationTokenAuthenticator extends AbstractAuthenticator
{
    public const IMPERSONATE_HEADER = 'X-Impersonate-User';
    private const SCHEME = 'Bearer '.Application::TOKEN_PREFIX;

    public function __construct(private readonly ApplicationRepository $applications)
    {
    }

    public function supports(Request $request): ?bool
    {
        return str_starts_with((string) $request->headers->get('Authorization'), self::SCHEME);
    }

    public function authenticate(Request $request): Passport
    {
        $secret = substr((string) $request->headers->get('Authorization'), \strlen('Bearer '));
        $application = $this->applications->findOneByToken($secret);
        if (null === $application || !$application->isEnabled()) {
            throw new CustomUserMessageAuthenticationException('Invalid application token.');
        }

        $impersonated = trim((string) $request->headers->get(self::IMPERSONATE_HEADER));
        if ('' === $impersonated) {
            $badge = new UserBadge('app:'.$application->getId()->toRfc4122(), static fn () => new ApplicationUser($application));
        } elseif (!$application->canImpersonate()) {
            throw new CustomUserMessageAuthenticationException('This application is not allowed to impersonate users.');
        } else {
            $badge = new UserBadge(mb_strtolower($impersonated));
        }

        $this->applications->touch($application);

        $passport = new SelfValidatingPassport($badge);
        $passport->setAttribute('application', $application);

        return $passport;
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $user = $passport->getUser();
        /** @var Application $application */
        $application = $passport->getAttribute('application');

        $roles = $user instanceof User
            ? Roles::delegated($user->getRoles(), Roles::APPLICATION, Roles::IMPERSONATION)
            : [Roles::APPLICATION];

        $token = new PostAuthenticationToken($user, $firewallName, $roles);
        $token->setAttribute(ActorContext::APPLICATION_ATTRIBUTE, $application->getId()->toRfc4122());

        return $token;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $message = $exception instanceof CustomUserMessageAuthenticationException
            ? $exception->getMessageKey()
            : 'Authentication failed.';

        return new JsonResponse(['code' => 401, 'message' => $message], Response::HTTP_UNAUTHORIZED);
    }
}
