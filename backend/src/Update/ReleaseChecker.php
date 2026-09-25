<?php

namespace App\Update;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Latest published version of Rocket Auth, from the GitHub releases (or, without releases, the version tags)
 * of UPDATE_REPOSITORY. Cached for an hour: the anonymous GitHub API allows 60 requests per hour.
 */
class ReleaseChecker
{
    private const CACHE_KEY = 'app.update.latest_release.v2';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        #[Autowire('%env(UPDATE_REPOSITORY)%')] private readonly string $repository,
        #[Autowire('%env(APP_VERSION)%')] private readonly string $version,
        #[Autowire('%kernel.project_dir%/VERSION')] private readonly string $versionFile,
    ) {
    }

    /** APP_VERSION (Docker images), else the VERSION file written by the update script (servers without Docker). */
    public function current(): AppVersion
    {
        $version = trim($this->version);
        if ('' === $version && is_readable($this->versionFile)) {
            $version = trim((string) file_get_contents($this->versionFile));
        }

        return AppVersion::parse($version);
    }

    public function isEnabled(): bool
    {
        return '' !== trim($this->repository);
    }

    public function repositoryUrl(): ?string
    {
        return $this->isEnabled() ? 'https://github.com/'.trim($this->repository) : null;
    }

    /** @throws UpdateException when GitHub cannot be reached */
    public function latest(bool $refresh = false): ?LatestRelease
    {
        if (!$this->isEnabled()) {
            return null;
        }
        if ($refresh) {
            $this->cache->delete(self::CACHE_KEY);
        }

        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): ?LatestRelease {
            $item->expiresAfter(3600);

            return $this->fetch();
        });
    }

    private function fetch(): ?LatestRelease
    {
        $best = null;
        foreach ($this->github('releases?per_page=30') as $release) {
            $version = AppVersion::releaseOf((string) ($release['tag_name'] ?? ''));
            if (null === $version || ($release['draft'] ?? false) || ($release['prerelease'] ?? false)) {
                continue;
            }
            if (null === $best || version_compare($version, $best->version, '>')) {
                $best = new LatestRelease(
                    $version,
                    (string) $release['tag_name'],
                    (string) (($release['name'] ?? '') ?: $release['tag_name']),
                    (string) $release['html_url'],
                    isset($release['published_at']) ? new \DateTimeImmutable($release['published_at']) : null,
                    ($release['body'] ?? '') ?: null,
                );
            }
        }
        if (null !== $best) {
            return $best;
        }

        // Tags without a GitHub release.
        foreach ($this->github('tags?per_page=100') as $tag) {
            $version = AppVersion::releaseOf((string) ($tag['name'] ?? ''));
            if (null !== $version && !str_contains($version, '-') && (null === $best || version_compare($version, $best->version, '>'))) {
                $best = new LatestRelease($version, $tag['name'], $tag['name'], $this->repositoryUrl().'/tree/'.rawurlencode($tag['name']));
            }
        }

        return $best;
    }

    /** @return list<array<string, mixed>> */
    private function github(string $path): array
    {
        try {
            $response = $this->httpClient->request('GET', \sprintf('https://api.github.com/repos/%s/%s', trim($this->repository), $path), [
                'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'Rocket-Mailer'],
                'timeout' => 10,
            ]);
            if (404 === $response->getStatusCode()) {
                throw new UpdateException(\sprintf('Le dépôt « %s » est introuvable sur GitHub (UPDATE_REPOSITORY).', trim($this->repository)));
            }
            if (\in_array($response->getStatusCode(), [403, 429], true)) {
                throw new UpdateException('GitHub limite le nombre de vérifications : réessayez dans une heure.');
            }

            return array_values(array_filter($response->toArray(), 'is_array'));
        } catch (HttpException $e) {
            throw new UpdateException('GitHub est injoignable : '.$e->getMessage(), previous: $e);
        }
    }
}
