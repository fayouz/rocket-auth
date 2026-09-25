<?php

namespace App\Security;

use App\Entity\User;
use App\Enum\UserSource;
use App\Ldap\UserDirectoryInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationSuccessHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * JSON login: local accounts are checked against their password hash, LDAP accounts by binding to the directory.
 * OpenID Connect accounts cannot use it: see App\Controller\OidcController.
 */
final class LoginAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly UserDirectoryInterface $directory,
        private readonly AuthenticationSuccessHandler $successHandler,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->isMethod('POST') && '/api/auth/login' === $request->getPathInfo();
    }

    public function authenticate(Request $request): Passport
    {
        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            throw new CustomUserMessageAuthenticationException('Invalid JSON payload.');
        }

        $email = $payload['email'] ?? null;
        $password = $payload['password'] ?? null;
        if (!\is_string($email) || !\is_string($password) || '' === trim($email) || '' === $password) {
            throw new CustomUserMessageAuthenticationException('Email and password are required.');
        }

        return new Passport(
            new UserBadge(mb_strtolower(trim($email))),
            new CustomCredentials(function (string $password, UserInterface $user): bool {
                if (!$user instanceof User) {
                    return false;
                }

                return match ($user->getSource()) {
                    UserSource::Local => null !== $user->getPassword() && $this->hasher->isPasswordValid($user, $password),
                    UserSource::Ldap => $this->directory->checkCredentials((string) $user->getLdapDn(), $password),
                    // Signs in through its OpenID Connect provider only (POST /api/auth/oidc/callback).
                    UserSource::Oidc => false,
                };
            }, $password),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return $this->successHandler->handleAuthenticationSuccess($token->getUser());
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $message = $exception instanceof CustomUserMessageAuthenticationException
            ? $exception->getMessageKey()
            : 'Invalid credentials.';

        return new JsonResponse(['code' => 401, 'message' => $message], Response::HTTP_UNAUTHORIZED);
    }
}
