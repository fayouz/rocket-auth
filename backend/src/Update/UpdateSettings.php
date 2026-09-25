<?php

namespace App\Update;

use App\Enum\UpdateMethod;
use App\Settings\Settings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Update method chosen by the administrators (stored in the database), else UPDATE_METHOD, else automatic:
 * Docker when the updater is configured, manual otherwise. Also the heartbeat of the scheduled task app:update:run.
 */
class UpdateSettings
{
    private const METHOD = 'update.method';
    private const HEARTBEAT = 'update.heartbeat';
    /** The scheduled task runs every minute: beyond this, it is considered missing. */
    public const HEARTBEAT_STALE_AFTER = 300;

    public function __construct(
        private readonly Settings $settings,
        private readonly Updater $updater,
        #[Autowire('%env(UPDATE_METHOD)%')] private readonly string $defaultMethod,
    ) {
    }

    public function method(): UpdateMethod
    {
        return UpdateMethod::tryFrom((string) $this->settings->get(self::METHOD)) ?? $this->defaultMethod();
    }

    public function isMethodStored(): bool
    {
        return null !== UpdateMethod::tryFrom((string) $this->settings->get(self::METHOD));
    }

    public function defaultMethod(): UpdateMethod
    {
        return UpdateMethod::tryFrom(trim($this->defaultMethod))
            ?? ($this->updater->isConfigured() ? UpdateMethod::Docker : UpdateMethod::Manual);
    }

    /** Persists the choice; the caller flushes. Null: back to the default. */
    public function setMethod(?UpdateMethod $method): void
    {
        null === $method ? $this->settings->remove(self::METHOD) : $this->settings->set(self::METHOD, $method->value);
    }

    public function heartbeat(): ?\DateTimeImmutable
    {
        $value = $this->settings->get(self::HEARTBEAT);

        return \is_string($value) ? new \DateTimeImmutable($value) : null;
    }

    /** Persists the time; the caller flushes. */
    public function beat(): void
    {
        $this->settings->set(self::HEARTBEAT, (new \DateTimeImmutable())->format(\DATE_ATOM));
    }

    public function isSchedulerAlive(): bool
    {
        $heartbeat = $this->heartbeat();

        return null !== $heartbeat && time() - $heartbeat->getTimestamp() <= self::HEARTBEAT_STALE_AFTER;
    }
}
