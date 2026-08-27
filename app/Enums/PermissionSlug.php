<?php

namespace App\Enums;

enum PermissionSlug: string
{
    case View = 'view';
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
