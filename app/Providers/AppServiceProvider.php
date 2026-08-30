<?php

namespace App\Providers;

use App\Models\Lead;
use App\Models\PlatformUser;
use App\Models\Role;
use App\Models\TenantUser;
use App\Observers\LeadObserver;
use App\Observers\PlatformUserObserver;
use App\Observers\RoleObserver;
use App\Observers\TenantUserObserver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
    }

    /**
     * Configure the Mother Admin bypass and protected-identity observers.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(function ($user) {
            return $user instanceof PlatformUser && $user->bypassesAuthorization() ? true : null;
        });

        PlatformUser::observe(PlatformUserObserver::class);
        TenantUser::observe(TenantUserObserver::class);
        Role::observe(RoleObserver::class);
        Lead::observe(LeadObserver::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
