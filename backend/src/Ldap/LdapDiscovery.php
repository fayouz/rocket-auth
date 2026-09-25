<?php

namespace App\Ldap;

/** Discovers LDAP endpoints from the current host and its DNS SRV records. */
final class LdapDiscovery
{
    /** @return list<array{url: string, reachable: bool, latencyMs: float|null}> */
    public function discover(string $configuredUrl): array
    {
        $candidates = [$configuredUrl];
        $parts = parse_url($configuredUrl);
        $host = \is_string($parts['host'] ?? null) ? $parts['host'] : null;
        if (null !== $host && false === filter_var($host, \FILTER_VALIDATE_IP)) {
            foreach (['ldap', 'ldaps'] as $service) {
                $records = @dns_get_record('_'.$service.'._tcp.'.$host, \DNS_SRV);
                foreach ($records ?: [] as $record) {
                    if (isset($record['target'], $record['port'])) {
                        $candidates[] = $service.'://'.rtrim($record['target'], '.').':'.$record['port'];
                    }
                }
            }
        }

        $result = [];
        foreach (array_values(array_unique(array_filter($candidates))) as $url) {
            $endpoint = parse_url($url);
            if (!\is_string($endpoint['host'] ?? null)) {
                continue;
            }
            $port = (int) ($endpoint['port'] ?? ('ldaps' === strtolower((string) ($endpoint['scheme'] ?? '')) ? 636 : 389));
            $start = hrtime(true);
            $stream = @stream_socket_client(
                'tcp://'.$endpoint['host'].':'.$port,
                $errno,
                $error,
                1.0,
                \STREAM_CLIENT_CONNECT,
            );
            if (false !== $stream) {
                fclose($stream);
            }
            $result[] = [
                'url' => $url,
                'reachable' => false !== $stream,
                'latencyMs' => false !== $stream ? round((hrtime(true) - $start) / 1e6, 1) : null,
            ];
        }

        return $result;
    }
}
