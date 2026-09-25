<?php

namespace App\Entity;

use Rocket\Core\Entity\User;
use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A refresh token (hash only). Rotated at each use: presenting a revoked one again revokes the whole family
 * (every token issued to this user for this client), as it means the token leaked.
 */
#[ORM\Entity(repositoryClass: RefreshTokenRepository::class)]
#[ORM\Index(name: 'idx_refresh_token_user_client', columns: ['user_id', 'client_id'])]
class RefreshToken
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OAuthClient $client;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** @var list<string> */
    #[ORM\Column]
    private array $scopes;

    #[ORM\Column]
    private \DateTimeImmutable $authTime;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /** @param list<string> $scopes */
    public function __construct(string $tokenHash, OAuthClient $client, User $user, array $scopes, \DateTimeImmutable $authTime, \DateTimeImmutable $createdAt, \DateTimeImmutable $expiresAt)
    {
        $this->id = Uuid::v7();
        $this->tokenHash = $tokenHash;
        $this->client = $client;
        $this->user = $user;
        $this->scopes = $scopes;
        $this->authTime = $authTime;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
    }

    public function getClient(): OAuthClient
    {
        return $this->client;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /** @return list<string> */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getAuthTime(): \DateTimeImmutable
    {
        return $this->authTime;
    }

    public function isActive(\DateTimeImmutable $now): bool
    {
        return null === $this->revokedAt && $this->expiresAt > $now;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function revoke(\DateTimeImmutable $at): void
    {
        $this->revokedAt ??= $at;
    }
}
