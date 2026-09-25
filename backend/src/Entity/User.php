<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Enum\UserSource;
use App\Repository\UserRepository;
use App\State\UserPasswordProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'uniq_user_external_id', columns: ['authentication_server_id', 'external_id'])]
#[UniqueEntity('email')]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_ADMIN')"),
        new Get(security: "is_granted('ROLE_ADMIN') or object == user"),
        new Post(security: "is_granted('ROLE_ADMIN')", processor: UserPasswordProcessor::class, validationContext: ['groups' => ['Default', 'user:create']]),
        new Patch(security: "is_granted('ROLE_ADMIN')", processor: UserPasswordProcessor::class),
        new Delete(security: "is_granted('ROLE_ADMIN') and object != user"),
    ],
    normalizationContext: ['groups' => ['user:read', 'tracking']],
    denormalizationContext: ['groups' => ['user:write']],
    order: ['lastName' => 'ASC', 'firstName' => 'ASC'],
)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['user:read', 'user:summary'])]
    private Uuid $id;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Groups(['user:read', 'user:write', 'user:summary'])]
    private string $email = '';

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['user:read', 'user:write', 'user:summary'])]
    private ?string $firstName = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['user:read', 'user:write', 'user:summary'])]
    private ?string $lastName = null;

    /** @var list<string> */
    #[ORM\Column]
    #[Groups(['user:read', 'user:write'])]
    private array $roles = [];

    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[Assert\NotBlank(groups: ['user:create'])]
    #[Assert\Length(min: 12)]
    #[Groups(['user:write'])]
    private ?string $plainPassword = null;

    #[ORM\Column(length: 16, enumType: UserSource::class)]
    #[Groups(['user:read'])]
    private UserSource $source = UserSource::Local;

    #[ORM\Column(length: 512, nullable: true)]
    #[Groups(['user:read'])]
    private ?string $ldapDn = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AuthenticationServer $authenticationServer = null;

    /** OpenID Connect: subject ("sub" claim) of the account at its authentication server. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['user:read'])]
    private ?\DateTimeImmutable $ldapSyncedAt = null;

    #[ORM\Column]
    #[Groups(['user:read', 'user:write'])]
    private bool $enabled = true;

    use TrackedTrait;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    #[Groups(['user:read', 'user:summary'])]
    public function getDisplayName(): string
    {
        $name = trim(($this->firstName ?? '').' '.($this->lastName ?? ''));

        return '' !== $name ? $name : $this->email;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values(array_unique(array_filter($roles, static fn (string $r) => 'ROLE_USER' !== $r)));

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): static
    {
        $this->plainPassword = $plainPassword;

        return $this;
    }

    public function getSource(): UserSource
    {
        return $this->source;
    }

    public function setSource(UserSource $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getLdapDn(): ?string
    {
        return $this->ldapDn;
    }

    public function setLdapDn(?string $ldapDn): static
    {
        $this->ldapDn = $ldapDn;

        return $this;
    }

    public function getAuthenticationServer(): ?AuthenticationServer
    {
        return $this->authenticationServer;
    }

    public function setAuthenticationServer(?AuthenticationServer $authenticationServer): static
    {
        $this->authenticationServer = $authenticationServer;

        return $this;
    }

    #[Groups(['user:read'])]
    public function getAuthenticationServerName(): ?string
    {
        return $this->authenticationServer?->getName();
    }

    public function getLdapSyncedAt(): ?\DateTimeImmutable
    {
        return $this->ldapSyncedAt;
    }

    public function setLdapSyncedAt(?\DateTimeImmutable $ldapSyncedAt): static
    {
        $this->ldapSyncedAt = $ldapSyncedAt;

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

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): static
    {
        $this->externalId = $externalId;

        return $this;
    }
}
