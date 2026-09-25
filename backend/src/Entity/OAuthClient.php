<?php

namespace App\Entity;

use Rocket\Core\Entity\TrackedTrait;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\OAuth\Scopes;
use App\Repository\OAuthClientRepository;
use App\State\OAuthClientCreateProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * An application that signs its users in with Rocket Auth (OAuth 2.0 / OpenID Connect client).
 * Confidential clients authenticate with a secret (only its hash is stored); public clients (SPA, mobile) must use PKCE.
 */
#[ORM\Entity(repositoryClass: OAuthClientRepository::class)]
#[UniqueEntity('clientId')]
#[ApiResource(
    shortName: 'OAuthClient',
    operations: [
        new GetCollection(uriTemplate: '/oauth/clients'),
        new Get(uriTemplate: '/oauth/clients/{id}'),
        new Post(uriTemplate: '/oauth/clients', processor: OAuthClientCreateProcessor::class, normalizationContext: ['groups' => ['oauth_client:read', 'oauth_client:secret', 'tracking']]),
        new Patch(uriTemplate: '/oauth/clients/{id}'),
        new Delete(uriTemplate: '/oauth/clients/{id}'),
    ],
    normalizationContext: ['groups' => ['oauth_client:read', 'tracking']],
    denormalizationContext: ['groups' => ['oauth_client:write']],
    security: "is_granted('ROLE_ADMIN')",
    order: ['name' => 'ASC'],
)]
class OAuthClient
{
    public const GRANT_TYPES = ['authorization_code', 'refresh_token', 'client_credentials'];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['oauth_client:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    #[Groups(['oauth_client:read', 'oauth_client:write'])]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['oauth_client:read', 'oauth_client:write'])]
    private ?string $description = null;

    /** Public identifier; generated when left empty. */
    #[ORM\Column(length: 80, unique: true)]
    #[Assert\Length(max: 80)]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9._-]*$/', message: 'Letters, digits, ".", "_" and "-" only.')]
    #[Groups(['oauth_client:read', 'oauth_client:write'])]
    private string $clientId = '';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $secretHash = null;

    #[ORM\Column(length: 16, nullable: true)]
    #[Groups(['oauth_client:read'])]
    private ?string $secretHint = null;

    /** Returned once, at creation or when regenerated. */
    #[Groups(['oauth_client:secret'])]
    private ?string $plainSecret = null;

    /** Confidential (server-side application, with a secret) or public (browser, mobile: PKCE only). */
    #[ORM\Column]
    #[Groups(['oauth_client:read', 'oauth_client:write'])]
    private bool $confidential = true;

    /** @var list<string> */
    #[ORM\Column]
    #[Groups(['oauth_client:read', 'oauth_client:write'])]
    private array $redirectUris = [];

    /** @var list<string> Where the application may send users after signing out (end_session_endpoint). */
    #[ORM\Column(options: ['default' => '[]'])]
    #[Groups(['oauth_client:read', 'oauth_client:write'])]
    private array $postLogoutRedirectUris = [];

    /** @var list<string> */
    #[ORM\Column]
    #[Assert\All([new Assert\Choice(choices: Scopes::ALL)])]
    #[Groups(['oauth_client:read', 'oauth_client:write'])]
    private array $allowedScopes = ['openid', 'email', 'profile', 'groups'];

    /** @var list<string> */
    #[ORM\Column]
    #[Assert\Count(min: 1)]
    #[Assert\All([new Assert\Choice(choices: self::GRANT_TYPES)])]
    #[Groups(['oauth_client:read', 'oauth_client:write'])]
    private array $grantTypes = ['authorization_code', 'refresh_token'];

    /** Address of the application: shown in the application switcher of the suite (GET /api/suite/apps) when set. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Assert\Url(requireTld: false)]
    #[Groups(['oauth_client:read', 'oauth_client:write'])]
    private ?string $homeUrl = null;

    /** Icon in the switcher, e.g. "i-lucide-printer". */
    #[ORM\Column(length: 80, nullable: true)]
    #[Assert\Regex(pattern: '/^i-[a-z0-9-]+$/', message: 'An icon name such as "i-lucide-printer".')]
    #[Groups(['oauth_client:read', 'oauth_client:write'])]
    private ?string $icon = null;

    /** First-party application: users are not asked for their consent. */
    #[ORM\Column]
    #[Groups(['oauth_client:read', 'oauth_client:write'])]
    private bool $trusted = false;

    #[ORM\Column]
    #[Groups(['oauth_client:read', 'oauth_client:write'])]
    private bool $enabled = true;

    #[ORM\Column(nullable: true)]
    #[Groups(['oauth_client:read'])]
    private ?\DateTimeImmutable $lastUsedAt = null;

    use TrackedTrait;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        foreach (['redirectUris' => $this->redirectUris, 'postLogoutRedirectUris' => $this->postLogoutRedirectUris] as $path => $uris) {
            foreach ($uris as $i => $uri) {
                $parts = parse_url($uri);
                if (false === $parts || !\in_array($parts['scheme'] ?? '', ['http', 'https'], true) || !isset($parts['host']) || isset($parts['fragment'])) {
                    $context->buildViolation('Each URI must be an absolute http(s) URL without fragment.')->atPath(\sprintf('%s[%d]', $path, $i))->addViolation();
                }
            }
        }
        if (\in_array('authorization_code', $this->grantTypes, true) && [] === $this->redirectUris) {
            $context->buildViolation('The authorization code flow needs at least one redirect URI.')->atPath('redirectUris')->addViolation();
        }
        if (!$this->confidential && \in_array('client_credentials', $this->grantTypes, true)) {
            $context->buildViolation('A public client cannot use client_credentials.')->atPath('grantTypes')->addViolation();
        }
    }

    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /** Generates a new secret, stores its hash and returns the plain value (shown once). */
    public function rotateSecret(): string
    {
        $secret = 'ras_'.bin2hex(random_bytes(32));
        $this->secretHash = self::hashSecret($secret);
        $this->secretHint = substr($secret, 0, 10);
        $this->plainSecret = $secret;

        return $secret;
    }

    /** Installs a known secret (demo environment only, see app:demo:seed). */
    public function useSecret(#[\SensitiveParameter] string $secret): void
    {
        if (\strlen($secret) < 24) {
            throw new \InvalidArgumentException('A client secret must be at least 24 characters long.');
        }
        $this->secretHash = self::hashSecret($secret);
        $this->secretHint = substr($secret, 0, 10);
    }

    public function checkSecret(#[\SensitiveParameter] string $secret): bool
    {
        return null !== $this->secretHash && hash_equals($this->secretHash, self::hashSecret($secret));
    }

    public function markUsed(\DateTimeImmutable $at): void
    {
        $this->lastUsedAt = $at;
    }

    public function allowsRedirectUri(string $uri): bool
    {
        return \in_array($uri, $this->redirectUris, true);
    }

    public function allowsGrant(string $grant): bool
    {
        return \in_array($grant, $this->grantTypes, true);
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
        $this->name = trim($name);

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

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function setClientId(string $clientId): static
    {
        $this->clientId = trim($clientId);

        return $this;
    }

    public function getSecretHint(): ?string
    {
        return $this->secretHint;
    }

    public function getPlainSecret(): ?string
    {
        return $this->plainSecret;
    }

    public function isConfidential(): bool
    {
        return $this->confidential;
    }

    public function setConfidential(bool $confidential): static
    {
        $this->confidential = $confidential;

        return $this;
    }

    /** @return list<string> */
    public function getRedirectUris(): array
    {
        return $this->redirectUris;
    }

    /** @param list<string> $redirectUris */
    public function setRedirectUris(array $redirectUris): static
    {
        $this->redirectUris = self::cleanList($redirectUris);

        return $this;
    }

    /** @return list<string> */
    public function getPostLogoutRedirectUris(): array
    {
        return $this->postLogoutRedirectUris;
    }

    /** @param list<string> $postLogoutRedirectUris */
    public function setPostLogoutRedirectUris(array $postLogoutRedirectUris): static
    {
        $this->postLogoutRedirectUris = self::cleanList($postLogoutRedirectUris);

        return $this;
    }

    /** @return list<string> */
    public function getAllowedScopes(): array
    {
        return $this->allowedScopes;
    }

    /** @param list<string> $allowedScopes */
    public function setAllowedScopes(array $allowedScopes): static
    {
        $this->allowedScopes = self::cleanList($allowedScopes);

        return $this;
    }

    /** @return list<string> */
    public function getGrantTypes(): array
    {
        return $this->grantTypes;
    }

    /** @param list<string> $grantTypes */
    public function setGrantTypes(array $grantTypes): static
    {
        $this->grantTypes = self::cleanList($grantTypes);

        return $this;
    }

    public function isTrusted(): bool
    {
        return $this->trusted;
    }

    public function setTrusted(bool $trusted): static
    {
        $this->trusted = $trusted;

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

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function cleanList(array $values): array
    {
        return array_values(array_unique(array_filter(array_map('trim', $values), static fn (string $v) => '' !== $v)));
    }

    public function getHomeUrl(): ?string
    {
        return $this->homeUrl;
    }

    public function setHomeUrl(?string $homeUrl): static
    {
        $this->homeUrl = '' === trim((string) $homeUrl) ? null : trim((string) $homeUrl);

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = '' === trim((string) $icon) ? null : trim((string) $icon);

        return $this;
    }
}
