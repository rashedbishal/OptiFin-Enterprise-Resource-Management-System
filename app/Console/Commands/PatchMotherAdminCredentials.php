<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Enums\PlatformUserType;
use App\Models\PlatformUser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

#[Signature('admin:patch-mother-admin-credentials')]
#[Description('Create the platform\'s single Mother Admin account, or update its credentials if it already exists')]
class PatchMotherAdminCredentials extends Command
{
    use PasswordValidationRules;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $motherAdmin = PlatformUser::where('user_type', PlatformUserType::MotherAdmin)->first();

        $name = $this->ask('Name', $motherAdmin?->name);
        $email = $this->ask('Email address', $motherAdmin?->email);
        $password = $this->secret('Password');
        $passwordConfirmation = $this->secret('Confirm password');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('platform_users', 'email')->ignore($motherAdmin?->id),
            ],
            'password' => $this->passwordRules(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        if ($motherAdmin) {
            $motherAdmin->update([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            $this->components->info("Mother Admin credentials updated for \"{$email}\".");

            return self::SUCCESS;
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
