<?php

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Outgoing HTTP requests of the test environment (framework.http_client.mock_response_factory):
 * answers from the registered routes, and records every request.
 */
final class HttpMock
{
    /** @var array<string, \Closure(string, string, array<string, mixed>): ResponseInterface> URL prefix => response factory */
    private static array $routes = [];

    /** @var list<array{method: string, url: string, headers: list<string>}> */
    public static array $requests = [];

    public static function reset(): void
    {
        self::$routes = [];
        self::$requests = [];
    }

    public static function json(string $urlPrefix, mixed $body, int $status = 200): void
    {
        self::on($urlPrefix, static fn () => new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]));
    }

    /** @param \Closure(string, string, array<string, mixed>): ResponseInterface $factory */
    public static function on(string $urlPrefix, \Closure $factory): void
    {
        self::$routes[$urlPrefix] = $factory;
    }

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        self::$requests[] = ['method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? []];
        foreach (self::$routes as $prefix => $factory) {
            if (str_starts_with($url, $prefix)) {
                return $factory($method, $url, $options);
            }
        }

        return new MockResponse('', ['error' => 'No mocked response for '.$method.' '.$url]);
    }
}
