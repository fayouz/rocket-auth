<?php

namespace App\Security;

use App\Entity\Application;
use Lexik\Bundle\JWTAuthenticationBundle\TokenExtractor\TokenExtractorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;

/**
 * Application secrets share the "Bearer" scheme with user JWTs: keep Lexik from trying to decode them.
 */
#[AsDecorator('lexik_jwt_authentication.extractor.chain_extractor')]
final class JwtTokenExtractor implements TokenExtractorInterface
{
    public function __construct(#[AutowireDecorated] private readonly TokenExtractorInterface $inner)
    {
    }

    public function extract(Request $request): string|false
    {
        $token = $this->inner->extract($request);

        return \is_string($token) && str_starts_with($token, Application::TOKEN_PREFIX) ? false : $token;
    }
}
