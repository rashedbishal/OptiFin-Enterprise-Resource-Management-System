<?php

namespace App\Enums;

enum ModuleScope: string
{
    case Platform = 'platform';
    case Tenant = 'tenant';
}
