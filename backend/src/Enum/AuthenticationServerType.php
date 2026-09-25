<?php

namespace App\Enum;

enum AuthenticationServerType: string
{
    case Ldap = 'ldap';
    case Oidc = 'oidc';
}
