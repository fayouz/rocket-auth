<?php

namespace App\Update;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Starts the update of the containers through the HTTP API of Watchtower (the "updater" service of compose.yaml):
 * it pulls the new images of the api, worker and front containers and restarts them. Migrations run when the api starts.
 */
class Updater
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(UPDATER_URL)%')] private readonly string $url,
        #[Autowire('%env(UPDATER_TOKEN)%')] private readonly string $token,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->url) && '' !== $this->token;
    }

    /** @throws UpdateException */
    public function start(): void
    {
        if (!$this->isConfigured()) {
            throw new UpdateException('La mise à jour automatique n’est pas configurée (UPDATER_URL, UPDATER_TOKEN).');
        }

        try {
            // Asynchronous: the update restarts this container, the request must not wait for it.
            $status = $this->httpClient->request('POST', rtrim($this->url, '/').'/v1/update?async=true', [
                'auth_bearer' => $this->token,
                'timeout' => 10,
            ])->getStatusCode();
        } catch (HttpException $e) {
            throw new UpdateException('Le service de mise à jour est injoignable : '.$e->getMessage(), previous: $e);
        }

        match (true) {
            $status >= 200 && $status < 300 => null,
            429 === $status => throw new UpdateException('Une mise à jour est déjà en cours.'),
            401 === $status, 403 === $status => throw new UpdateException('Le service de mise à jour refuse le jeton (UPDATER_TOKEN).'),
            default => throw new UpdateException(\sprintf('Le service de mise à jour a répondu %d.', $status)),
        };
    }
}
