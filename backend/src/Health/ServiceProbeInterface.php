<?php

namespace App\Health;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A network dependency of a domain module (another middleware, a document engine, printers…), checked by the worker
 * every 5 minutes with the LDAP server and the OpenID Connect providers, and shown on the dashboard.
 */
#[AutoconfigureTag('app.service_probe')]
interface ServiceProbeInterface
{
    /** Stable identifier of the dashboard service, e.g. "mailer". */
    public function id(): string;

    public function label(): string;

    /**
     * What to check, by item identifier: one item for a single server, one per printer… Empty: not configured.
     *
     * @return iterable<string, array{name: string, check: callable(): string}> check() returns a detail, or throws on failure
     */
    public function targets(): iterable;
}
