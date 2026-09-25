<?php

namespace App\Entity;

use Rocket\Core\Entity\User;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\ConsentRepository;
use App\State\ConsentDeleteProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * What a user allowed an application to read. Users list and revoke their own consents ("Applications autorisées");
 * revoking also revokes the refresh tokens of that application.
 */
#[ORM\Entity(repositoryClass: ConsentRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_consent_user_client', columns: ['user_id', 'client_id'])]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Delete(security: 'object.getUser() == user', processor: ConsentDeleteProcessor::class),
    ],
    normalizationContext: ['groups' => ['consent:read']],
    order: ['grantedAt' => 'DESC'],
    paginationEnabled: false,
)]
class Consent
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['consent:read'])]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OAuthClient $client;

    /** @var list<string> */
    #[ORM\Column]
    #[Groups(['consent:read'])]
    private array $scopes = [];

    #[ORM\Column]
    #[Groups(['consent:read'])]
    private \DateTimeImmutable $grantedAt;

    #[ORM\Column(nullable: true)]
    #[Groups(['consent:read'])]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(User $user, OAuthClient $client, \DateTimeImmutable $grantedAt)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->client = $client;
        $this->grantedAt = $grantedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getClient(): OAuthClient
    {
        return $this->client;
    }

    #[Groups(['consent:read'])]
    public function getClientName(): string
    {
        return $this->client->getName();
    }

    #[Groups(['consent:read'])]
    public function getClientDescription(): ?string
    {
        return $this->client->getDescription();
    }

    /** @return list<string> */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /** @param list<string> $scopes */
    public function grant(array $scopes, \DateTimeImmutable $at): void
    {
        $this->scopes = array_values(array_unique([...$this->scopes, ...$scopes]));
        $this->grantedAt = $at;
    }

    public function covers(array $scopes): bool
    {
        return [] === array_diff($scopes, $this->scopes);
    }

    public function getGrantedAt(): \DateTimeImmutable
    {
        return $this->grantedAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(\DateTimeImmutable $at): void
    {
        $this->lastUsedAt = $at;
    }
}
