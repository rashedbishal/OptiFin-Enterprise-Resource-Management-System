<?php

namespace App\Enums;

enum PlatformUserType: string
{
    case MotherAdmin = 'mother_admin';
    case PlatformAdmin = 'platform_admin';
}
