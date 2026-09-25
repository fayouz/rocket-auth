<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\ApplicationRepository;
use App\State\ApplicationCreateProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An external application authenticated by a secret token, optionally allowed to act on behalf of users.
 */
#[ORM\Entity(repositoryClass: ApplicationRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(processor: ApplicationCreateProcessor::class, normalizationContext: ['groups' => ['app:read', 'app:token', 'tracking']]),
        new Patch(),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['app:read', 'tracking']],
    denormalizationContext: ['groups' => ['app:write']],
    security: "is_granted('ROLE_ADMIN')",
    order: ['name' => 'ASC'],
)]
class Application
{
    public const TOKEN_PREFIX = 'raa_';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['app:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    #[Groups(['app:read', 'app:write'])]
    private string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['app:read', 'app:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash = '';

    #[ORM\Column(length: 16)]
    #[Groups(['app:read'])]
    private string $tokenHint = '';

    #[Groups(['app:token'])]
    private ?string $plainToken = null;

    #[ORM\Column]
    #[Groups(['app:read', 'app:write'])]
    private bool $canImpersonate = false;

    #[ORM\Column]
    #[Groups(['app:read', 'app:write'])]
    private bool $enabled = true;

    #[ORM\Column(nullable: true)]
    #[Groups(['app:read'])]
    private ?\DateTimeImmutable $lastUsedAt = null;

    use TrackedTrait;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Generates a new secret, stores its hash and returns the plain value (shown once). */
    public function rotateToken(): string
    {
        $token = self::TOKEN_PREFIX.bin2hex(random_bytes(32));
        $this->tokenHash = self::hashToken($token);
        $this->tokenHint = substr($token, 0, 10);
        $this->plainToken = $token;

        return $token;
    }

    /** Installs a known secret (demo environment only, see app:demo:seed). */
    public function useToken(string $token): void
    {
        if (!str_starts_with($token, self::TOKEN_PREFIX) || \strlen($token) < 36) {
            throw new \InvalidArgumentException(\sprintf('An application token must start with "%s" and be at least 36 characters long.', self::TOKEN_PREFIX));
        }

        $this->tokenHash = self::hashToken($token);
        $this->tokenHint = substr($token, 0, 10);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getTokenHint(): string
    {
        return $this->tokenHint;
    }

    public function getPlainToken(): ?string
    {
        return $this->plainToken;
    }

    public function canImpersonate(): bool
    {
        return $this->canImpersonate;
    }

    /** Accessor used by the serializer (it does not map a bare "canImpersonate()" to the property). */
    public function getCanImpersonate(): bool
    {
        return $this->canImpersonate;
    }

    public function setCanImpersonate(bool $canImpersonate): static
    {
        $this->canImpersonate = $canImpersonate;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }
}
