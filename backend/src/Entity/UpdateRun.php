<?php

namespace App\Entity;

use App\Enum\UpdateMethod;
use App\Enum\UpdateRunStatus;
use App\Repository\UpdateRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** One update of the platform, started from Administration → Mises à jour. */
#[ORM\Entity(repositoryClass: UpdateRunRepository::class)]
#[ORM\Index(columns: ['status'])]
class UpdateRun
{
    /** Longest log kept (the end of the output). */
    private const LOG_MAX = 65536;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 20, enumType: UpdateMethod::class)]
    private UpdateMethod $method;

    #[ORM\Column(length: 20, enumType: UpdateRunStatus::class)]
    private UpdateRunStatus $status;

    /** Version installed when the update was requested. */
    #[ORM\Column(length: 80)]
    private string $fromVersion;

    /** Tag to install ("v0.7.0"); null: the latest one. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $target;

    #[ORM\Column(type: Types::TEXT)]
    private string $log = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    use TrackedTrait;

    public function __construct(UpdateMethod $method, string $fromVersion, ?string $target)
    {
        $this->id = Uuid::v7();
        $this->method = $method;
        $this->fromVersion = $fromVersion;
        $this->target = $target;
        $this->status = UpdateMethod::Script === $method ? UpdateRunStatus::Requested : UpdateRunStatus::Started;
        if (UpdateMethod::Docker === $method) {
            $this->startedAt = new \DateTimeImmutable();
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMethod(): UpdateMethod
    {
        return $this->method;
    }

    public function getStatus(): UpdateRunStatus
    {
        return $this->status;
    }

    public function getFromVersion(): string
    {
        return $this->fromVersion;
    }

    public function getTarget(): ?string
    {
        return $this->target;
    }

    public function getLog(): string
    {
        return $this->log;
    }

    public function appendLog(string $output): void
    {
        $this->log .= $output;
        if (\strlen($this->log) > self::LOG_MAX) {
            $this->log = "…\n".substr($this->log, -self::LOG_MAX);
        }
    }

    public function markRunning(): void
    {
        $this->status = UpdateRunStatus::Running;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function finish(UpdateRunStatus $status): void
    {
        $this->status = $status;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => (string) $this->id,
            'method' => $this->method->value,
            'status' => $this->status->value,
            'fromVersion' => $this->fromVersion,
            'target' => $this->target,
            'log' => $this->log,
            'requestedAt' => $this->createdAt?->format(\DATE_ATOM),
            'requestedBy' => $this->createdBy,
            'startedAt' => $this->startedAt?->format(\DATE_ATOM),
            'finishedAt' => $this->finishedAt?->format(\DATE_ATOM),
        ];
    }
}
