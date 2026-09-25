<?php

namespace App\Enum;

enum UserSource: string
{
    case Local = 'local';
    case Ldap = 'ldap';
    case Oidc = 'oidc';
}
