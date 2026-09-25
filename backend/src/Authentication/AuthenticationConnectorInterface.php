<?php

namespace App\Authentication;

use App\Enum\AuthenticationServerType;
use App\Ldap\UserDirectoryInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.authentication_connector')]
interface AuthenticationConnectorInterface extends UserDirectoryInterface
{
    public function supports(AuthenticationServerType $type): bool;
}
