<?php

namespace App\Controller;

use App\Entity\UpdateRun;
use App\Enum\UpdateMethod;
use App\Repository\UpdateRunRepository;
use App\Security\Roles;
use App\Update\ReleaseChecker;
use App\Update\ScriptUpdater;
use App\Update\UpdateException;
use App\Update\UpdateManager;
use App\Update\UpdateMethodInput;
use App\Update\Updater;
use App\Update\UpdateSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Version of the platform, available updates, update method and one-click update (administrators). */
final class SystemUpdateController extends AbstractController
{
    public function __construct(
        private readonly ReleaseChecker $releases,
        private readonly UpdateSettings $settings,
        private readonly UpdateManager $updates,
    ) {
    }

    #[Route('/api/system/version', name: 'api_system_version', methods: ['GET'])]
    public function version(): JsonResponse
    {
        $current = $this->releases->current();

        return $this->json(['version' => $current->label(), 'release' => $current->release]);
    }

    #[IsGranted(Roles::ADMIN)]
    #[Route('/api/system/update', name: 'api_system_update', methods: ['GET'])]
    public function status(Updater $docker, ScriptUpdater $script, UpdateRunRepository $runs, #[MapQueryParameter] bool $refresh = false): JsonResponse
    {
        $run = $this->updates->reconcile();
        $current = $this->releases->current();
        $latest = null;
        $error = null;
        try {
            $latest = $this->releases->latest($refresh);
        } catch (UpdateException $e) {
            $error = $e->getMessage();
        }

        return $this->json([
            'current' => ['version' => $current->label(), 'release' => $current->release, 'raw' => $current->raw],
            'latest' => $latest?->toArray(),
            // null: this build has no version number to compare (development build, branch image).
            'updateAvailable' => null === $latest ? false : $current->isOlderThan($latest->version),
            'checkEnabled' => $this->releases->isEnabled(),
            'repositoryUrl' => $this->releases->repositoryUrl(),
            'error' => $error,
            'method' => $this->settings->method()->value,
            'defaultMethod' => $this->settings->defaultMethod()->value,
            'methodSource' => $this->settings->isMethodStored() ? 'database' : 'environment',
            'methods' => [
                'docker' => ['configured' => $docker->isConfigured()],
                'script' => [
                    'script' => $script->script(),
                    'installed' => $script->isInstalled(),
                    'schedulerAlive' => $this->settings->isSchedulerAlive(),
                    'heartbeatAt' => $this->settings->heartbeat()?->format(\DATE_ATOM),
                ],
            ],
            'run' => $run?->toArray(),
            'history' => array_map(static fn (UpdateRun $r) => array_diff_key($r->toArray(), ['log' => true]), $runs->recent(5)),
        ]);
    }

    #[IsGranted(Roles::ADMIN)]
    #[Route('/api/system/update/method', name: 'api_system_update_method', methods: ['PUT'])]
    public function method(#[MapRequestPayload] UpdateMethodInput $input, EntityManagerInterface $em): JsonResponse
    {
        $this->settings->setMethod(null === $input->method ? null : UpdateMethod::from($input->method));
        $em->flush();

        return $this->json(['method' => $this->settings->method()->value, 'methodSource' => $this->settings->isMethodStored() ? 'database' : 'environment']);
    }

    #[IsGranted(Roles::ADMIN)]
    #[Route('/api/system/update', name: 'api_system_update_start', methods: ['POST'])]
    public function start(): JsonResponse
    {
        try {
            // The script installs the latest published version (or, without a release list, the latest tag).
            $latest = $this->releases->isEnabled() ? $this->safeLatest() : null;
            $run = $this->updates->request($latest?->tag);
        } catch (UpdateException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return $this->json(['started' => true, 'from' => $run->getFromVersion(), 'run' => $run->toArray()], Response::HTTP_ACCEPTED);
    }

    #[IsGranted(Roles::ADMIN)]
    #[Route('/api/system/update/cancel', name: 'api_system_update_cancel', methods: ['POST'])]
    public function cancel(): JsonResponse
    {
        try {
            $run = $this->updates->cancel();
        } catch (UpdateException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return $this->json(['run' => $run?->toArray()]);
    }

    private function safeLatest(): ?\App\Update\LatestRelease
    {
        try {
            return $this->releases->latest();
        } catch (UpdateException) {
            return null;
        }
    }
}
