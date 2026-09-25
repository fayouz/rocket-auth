<?php

namespace App\Entity;

use App\Repository\ServiceCheckRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Last result of a network check (LDAP server, SMTP or IMAP of a sending mailbox), run in the background
 * by the scheduler (see App\Health\HealthChecker) and shown on the dashboard.
 */
#[ORM\Entity(repositoryClass: ServiceCheckRepository::class)]
class ServiceCheck
{
    public const OK = 'operational';
    public const FAILING = 'down';

    /** "ldap", "mailbox:<id>:smtp", "mailbox:<id>:imap". */
    #[ORM\Id]
    #[ORM\Column(length: 120)]
    private string $id;

    #[ORM\Column(length: 20)]
    private string $status = self::OK;

    #[ORM\Column(type: Types::TEXT)]
    private string $detail = '';

    #[ORM\Column(nullable: true)]
    private ?float $latencyMs = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $checkedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastOkAt = null;

    /** First failed check of the current failure streak. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $failingSince = null;

    public function __construct(string $id)
    {
        $this->id = $id;
        $this->checkedAt = new \DateTimeImmutable();
    }

    public function record(bool $ok, string $detail, ?float $latencyMs, \DateTimeImmutable $at): void
    {
        $this->status = $ok ? self::OK : self::FAILING;
        $this->detail = mb_substr($detail, 0, 2000);
        $this->latencyMs = $latencyMs;
        $this->checkedAt = $at;
        if ($ok) {
            $this->lastOkAt = $at;
            $this->failingSince = null;
        } else {
            $this->failingSince ??= $at;
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function isOk(): bool
    {
        return self::OK === $this->status;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getDetail(): string
    {
        return $this->detail;
    }

    public function getLatencyMs(): ?float
    {
        return $this->latencyMs;
    }

    public function getCheckedAt(): \DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function getLastOkAt(): ?\DateTimeImmutable
    {
        return $this->lastOkAt;
    }

    public function getFailingSince(): ?\DateTimeImmutable
    {
        return $this->failingSince;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'detail' => $this->detail,
            'latencyMs' => $this->latencyMs,
            'checkedAt' => $this->checkedAt->format(\DATE_ATOM),
            'lastOkAt' => $this->lastOkAt?->format(\DATE_ATOM),
            'failingSince' => $this->failingSince?->format(\DATE_ATOM),
        ];
    }
}
