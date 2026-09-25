<?php

namespace App\Entity;

use Rocket\Core\Entity\User;
use App\Repository\SignInEventRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Audit trail of the sign-ins (dashboard): password logins, authorizations granted to applications, refused ones. */
#[ORM\Entity(repositoryClass: SignInEventRepository::class)]
#[ORM\Index(name: 'idx_sign_in_event_at', columns: ['at'])]
class SignInEvent
{
    public const LOGIN = 'login';
    public const AUTHORIZE = 'authorize';
    public const DENIED = 'denied';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column]
    private \DateTimeImmutable $at;

    public function __construct(
        #[ORM\Column(length: 16)]
        private string $type,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?User $user,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?OAuthClient $client,
        \DateTimeImmutable $at,
    ) {
        $this->id = Uuid::v7();
        $this->at = $at;
    }

    public function getType(): string
    {
        return $this->type;
    }
}
