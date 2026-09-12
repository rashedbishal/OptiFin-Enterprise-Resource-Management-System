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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->configureRateLimiting();
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
     * Feature #13 — rate limiting for the public Website Form Builder
     * submission endpoint only (never the legacy website-form webhook,
     * which this feature deliberately leaves untouched). Two independent
     * limiters, both keyed by IP alongside a resource identifier so one
     * abusive visitor can't exhaust either ceiling for every other
     * visitor of the same form/tenant: per-form catches focused abuse of
     * one form; per-tenant catches an attacker spreading requests across a
     * tenant's several forms from one IP. Thresholds are reasonable Phase 1
     * defaults, not deeply researched — easily tunable later.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('website-form-per-form', function (Request $request): Limit {
            $formToken = (string) $request->route('form_token');

            return Limit::perMinute(20)->by('website-form-per-form:'.$formToken.'|'.$request->ip());
        });

        RateLimiter::for('website-form-per-tenant', function (Request $request): Limit {
            $tenantCode = (string) $request->route('tenant_code');

            return Limit::perMinute(60)->by('website-form-per-tenant:'.$tenantCode.'|'.$request->ip());
        });
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
