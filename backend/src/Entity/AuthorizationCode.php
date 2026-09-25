<?php

namespace App\Entity;

use App\Repository\AuthorizationCodeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** A one-time authorization code (hash only), valid one minute, bound to the client, redirect URI and PKCE challenge. */
#[ORM\Entity(repositoryClass: AuthorizationCodeRepository::class)]
#[ORM\Index(name: 'idx_authorization_code_expires', columns: ['expires_at'])]
class AuthorizationCode
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 64, unique: true)]
    private string $codeHash;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OAuthClient $client;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::TEXT)]
    private string $redirectUri;

    /** @var list<string> */
    #[ORM\Column]
    private array $scopes;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nonce;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $codeChallenge;

    #[ORM\Column]
    private \DateTimeImmutable $authTime;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    /** @param list<string> $scopes */
    public function __construct(string $codeHash, OAuthClient $client, User $user, string $redirectUri, array $scopes, ?string $nonce, ?string $codeChallenge, \DateTimeImmutable $authTime, \DateTimeImmutable $expiresAt)
    {
        $this->id = Uuid::v7();
        $this->codeHash = $codeHash;
        $this->client = $client;
        $this->user = $user;
        $this->redirectUri = $redirectUri;
        $this->scopes = $scopes;
        $this->nonce = $nonce;
        $this->codeChallenge = $codeChallenge;
        $this->authTime = $authTime;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getClient(): OAuthClient
    {
        return $this->client;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    /** @return list<string> */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getNonce(): ?string
    {
        return $this->nonce;
    }

    public function getCodeChallenge(): ?string
    {
        return $this->codeChallenge;
    }

    public function getAuthTime(): \DateTimeImmutable
    {
        return $this->authTime;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    public function isUsed(): bool
    {
        return null !== $this->usedAt;
    }

    public function markUsed(\DateTimeImmutable $at): void
    {
        $this->usedAt = $at;
    }
}
