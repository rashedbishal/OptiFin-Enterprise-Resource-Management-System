<?php

namespace App\Actions\Platform;

use App\Enums\PlatformUserType;
use App\Models\PlatformUser;

class CreatePlatformAdmin
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): PlatformUser
    {
        return PlatformUser::create([
            'user_type' => PlatformUserType::PlatformAdmin,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role_id' => $data['role_id'],
        ]);
    }
}
