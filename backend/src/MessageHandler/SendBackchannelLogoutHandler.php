<?php

namespace App\MessageHandler;

use App\Message\SendBackchannelLogout;
use App\OAuth\TokenIssuer;
use App\Repository\OAuthClientRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** POSTs the logout token to the application's back-channel logout URI; any other answer than 2xx is retried. */
#[AsMessageHandler]
final class SendBackchannelLogoutHandler
{
    public function __construct(
        private readonly OAuthClientRepository $clients,
        private readonly TokenIssuer $tokens,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendBackchannelLogout $message): void
    {
        $client = $this->clients->findOneBy(['clientId' => $message->clientId]);
        $uri = $client?->getBackchannelLogoutUri();
        if (null === $client || !$client->isEnabled() || null === $uri) {
            return;
        }

        // A fresh token at each attempt: the application refuses old ones (iat) and replayed ones (jti).
        $response = $this->httpClient->request('POST', $uri, [
            'body' => ['logout_token' => $this->tokens->logoutToken($client, $message->subject, $message->sid)],
            'headers' => ['Accept' => 'application/json'],
            'max_redirects' => 0,
            'timeout' => 10,
        ]);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(\sprintf('Back-channel logout of %s refused by %s (HTTP %d): %s', $message->clientId, $uri, $status, mb_substr($response->getContent(false), 0, 300)));
        }
        $this->logger->info('Back-channel logout sent to {client}.', ['client' => $message->clientId]);
    }
}
