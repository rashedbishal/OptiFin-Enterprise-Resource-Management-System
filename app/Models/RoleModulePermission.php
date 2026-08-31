<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single "this role may {permission} within {module}" grant. Distinct
 * from the flat role<->permission pivot it replaced: the same permission
 * can be granted for one module a role has and withheld for another.
 */
class RoleModulePermission extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'role_id',
        'module_id',
        'permission_id',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }
}
