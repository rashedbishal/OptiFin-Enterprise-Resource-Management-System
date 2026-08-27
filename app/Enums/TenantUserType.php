<?php

namespace App\Enums;

enum TenantUserType: string
{
    case ClientAdmin = 'client_admin';
    case ClientUser = 'client_user';
}
