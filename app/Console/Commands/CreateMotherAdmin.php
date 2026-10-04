<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Enums\PlatformUserType;
use App\Models\PlatformUser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('admin:create-mother-admin-credentials')]
#[Description('Create the platform\'s single Mother Admin account')]
class CreateMotherAdmin extends Command
{
    use PasswordValidationRules;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (PlatformUser::where('user_type', PlatformUserType::MotherAdmin)->exists()) {
            $this->components->error('A Mother Admin already exists. Only one may ever exist.');

            return self::FAILURE;
        }

        $name = $this->ask('Name');
        $email = $this->ask('Email address');
        $password = $this->secret('Password');
        $passwordConfirmation = $this->secret('Confirm password');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:platform_users,email'],
            'password' => $this->passwordRules(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        PlatformUser::create([
            'user_type' => PlatformUserType::MotherAdmin,
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role_id' => null,
        ]);

        $this->components->info("Mother Admin \"{$email}\" created.");

        return self::SUCCESS;
    }
}
