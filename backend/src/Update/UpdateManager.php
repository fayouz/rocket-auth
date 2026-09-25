<?php

namespace App\Update;

use App\Entity\UpdateRun;
use App\Enum\UpdateMethod;
use App\Enum\UpdateRunStatus;
use App\Repository\UpdateRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/** Starts updates with the chosen method, and runs the ones waiting for the scheduled task (script method). */
class UpdateManager
{
    /** A Docker update that shows no new version after this long is considered failed. */
    private const DOCKER_TIMEOUT = 900;

    public function __construct(
        private readonly UpdateSettings $settings,
        private readonly ReleaseChecker $releases,
        private readonly Updater $docker,
        private readonly ScriptUpdater $script,
        private readonly UpdateRunRepository $runs,
        private readonly EntityManagerInterface $em,
        private readonly LockFactory $locks,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param string|null $target tag to install; null: the latest one
     *
     * @throws UpdateException
     */
    public function request(?string $target): UpdateRun
    {
        $this->reconcile();
        $active = $this->runs->latest();
        if (null !== $active && $active->getStatus()->isActive()) {
            throw new UpdateException('Une mise à jour est déjà en cours.');
        }

        $method = $this->settings->method();
        $run = match ($method) {
            UpdateMethod::Manual => throw new UpdateException('La méthode de mise à jour est « manuelle » : lancez les commandes sur le serveur.'),
            // Watchtower installs the images of the tags the containers use (latest…), not a given version.
            UpdateMethod::Docker => new UpdateRun($method, $this->releases->current()->label(), null),
            UpdateMethod::Script => new UpdateRun($method, $this->releases->current()->label(), $target),
        };
        if (UpdateMethod::Docker === $method) {
            $this->docker->start();
        } elseif (!$this->script->isInstalled()) {
            throw new UpdateException(\sprintf('Le script de mise à jour « %s » est introuvable ou n’est pas exécutable.', $this->script->script()));
        }

        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    /** Cancels an update still waiting for the scheduled task. */
    public function cancel(): ?UpdateRun
    {
        $run = $this->runs->nextRequested();
        if (null === $run) {
            throw new UpdateException('Aucune mise à jour en attente.');
        }
        $run->finish(UpdateRunStatus::Cancelled);
        $this->em->flush();

        return $run;
    }

    /** Docker updates end without telling: done once another version answers, failed after a while. */
    public function reconcile(): ?UpdateRun
    {
        $run = $this->runs->latest();
        if (null === $run || UpdateRunStatus::Started !== $run->getStatus()) {
            return $run;
        }
        if ($this->releases->current()->label() !== $run->getFromVersion()) {
            $run->finish(UpdateRunStatus::Succeeded);
            $this->em->flush();
        } elseif (null !== $run->getStartedAt() && time() - $run->getStartedAt()->getTimestamp() > self::DOCKER_TIMEOUT) {
            $run->appendLog("Aucune nouvelle version n'a démarré : consultez les journaux du service updater.\n");
            $run->finish(UpdateRunStatus::Failed);
            $this->em->flush();
        }

        return $run;
    }

    /**
     * Scheduled task (every minute): records the heartbeat, then runs the update waiting, if any.
     *
     * @param callable(string): void $onOutput
     */
    public function runPending(callable $onOutput): ?UpdateRun
    {
        $this->settings->beat();
        $this->em->flush();

        $lock = $this->locks->createLock('app-update-run', ttl: 3600);
        if (!$lock->acquire()) {
            return null;
        }

        try {
            $run = $this->runs->nextRequested();
            if (null === $run) {
                return null;
            }
            $run->markRunning();
            $this->em->flush();

            $lastFlush = microtime(true);
            try {
                $code = $this->script->run($run->getTarget(), function (string $output) use ($run, $onOutput, &$lastFlush): void {
                    $onOutput($output);
                    $run->appendLog($output);
                    // Live log for the administration page.
                    if (microtime(true) - $lastFlush > 2) {
                        $this->em->flush();
                        $lastFlush = microtime(true);
                    }
                });
                $run->finish(0 === $code ? UpdateRunStatus::Succeeded : UpdateRunStatus::Failed);
                if (0 !== $code) {
                    $run->appendLog(\sprintf("\nLe script s'est arrêté avec le code %d.\n", $code));
                }
            } catch (\Throwable $e) {
                $this->logger->error('Update script failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
                $run->appendLog("\n".$e->getMessage()."\n");
                $run->finish(UpdateRunStatus::Failed);
            }
            $this->em->flush();

            return $run;
        } finally {
            $lock->release();
        }
    }
}
