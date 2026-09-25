<?php

namespace App\Update;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Update of a server without Docker: runs the update script (deploy/update.sh by default), which checks out the new
 * version, installs the dependencies, applies the migrations, builds the interface and restarts the services.
 * Called by the scheduled task app:update:run, as the user owning the files, never by the web server.
 */
class ScriptUpdater
{
    public function __construct(
        #[Autowire('%env(resolve:UPDATE_SCRIPT)%')] private readonly string $script,
        #[Autowire('%env(UPDATE_RESTART_COMMAND)%')] private readonly string $restartCommand,
        #[Autowire('%env(int:UPDATE_SCRIPT_TIMEOUT)%')] private readonly int $timeout,
    ) {
    }

    public function script(): string
    {
        return realpath($this->script) ?: $this->script;
    }

    public function isInstalled(): bool
    {
        return is_file($this->script) && is_executable($this->script);
    }

    /**
     * @param callable(string): void $onOutput receives the output as it comes (stdout and stderr)
     *
     * @return int exit code of the script
     */
    public function run(?string $target, callable $onOutput): int
    {
        if (!$this->isInstalled()) {
            throw new UpdateException(\sprintf('Le script de mise à jour « %s » est introuvable ou n’est pas exécutable.', $this->script));
        }
        if (null !== $target && null === AppVersion::releaseOf($target)) {
            throw new UpdateException(\sprintf('Version à installer invalide : « %s ».', $target));
        }

        $process = new Process([$this->script()], \dirname($this->script()), [
            'TARGET_VERSION' => $target ?? '',
            'UPDATE_RESTART_COMMAND' => $this->restartCommand,
        ], timeout: $this->timeout);

        return $process->run(static function (string $type, string $output) use ($onOutput): void {
            $onOutput($output);
        });
    }
}
