<?php

namespace App\Providers;

use App\Enums\LeadSourceType;
use App\Enums\MessageChannel;
use App\Enums\TenantStatus;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\PlatformUser;
use App\Models\Role;
use App\Models\Tenant;
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
        $this->configureUploads();
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
    /**
     * Livewire's temporary-upload pre-check defaults to 12 MB, below the
     * 25 MB PDF ceiling (MessageChannel::MAX_DOCUMENT_BYTES) the composer
     * allows — raised to that ceiling plus 5 MB of headroom, so a file just
     * over a channel's limit reaches the composer's own, specific
     * "allows up to N MB" message instead of Livewire's generic upload
     * failure. Every component still applies its own, stricter validation
     * after the upload (e.g. the CSV import's 10 MB).
     */
    protected function configureUploads(): void
    {
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.intdiv(MessageChannel::MAX_DOCUMENT_BYTES + 5 * 1024 * 1024, 1024)]]);
    }

    protected function configureRateLimiting(): void
    {
        // WhatsApp inbound calling (Step 4) — the claim/answer/accept/reject
        // actions, per authenticated user (per IP as a fallback). 60 a minute is
        // far above any real call (a handful of requests plus retries) and only
        // there to blunt a runaway client. Evaluated by ThrottleRequestsFailOpen:
        // the actions' real protection is their atomic compare-and-set, so an
        // unreachable limiter backend must not stop an agent answering a call.
        RateLimiter::for('lead-call-actions', function (Request $request): Limit {
            return Limit::perMinute(60)->by('lead-call-actions:'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });

        RateLimiter::for('website-form-per-form', function (Request $request): Limit {
            $formToken = (string) $request->route('form_token');

            return Limit::perMinute(20)->by('website-form-per-form:'.$formToken.'|'.$request->ip());
        });

        RateLimiter::for('website-form-per-tenant', function (Request $request): Limit {
            $tenantCode = (string) $request->route('tenant_code');

            return Limit::perMinute(60)->by('website-form-per-tenant:'.$tenantCode.'|'.$request->ip());
        });

        // SEC-2 — the legacy website-form webhook (App\Http\Controllers\Api\
        // LeadWebhookController::websiteForm()) has no per-form token the
        // way the builder submission endpoint above does — it's one shared
        // API key per WebsiteForm source — so these two limiters key on the
        // resolved LeadSource.id instead: per-source-ip (20/min) catches
        // one abusive origin, per-source (60/min) catches abuse spread
        // across many IPs against the same source, mirroring the same
        // "resource + IP, and resource alone" two-tier shape as the pair
        // above without inventing a form-token dimension that doesn't
        // exist here. Evaluated by App\Http\Middleware\ThrottleRequestsFailOpen
        // rather than the stock `throttle:` middleware — see that class's
        // own docblock for why. If the tenant_code/source can't be
        // resolved, Limit::none() (Unlimited) is returned deliberately: an
        // unresolvable source has nothing meaningful to rate-limit, and the
        // controller's own existing resolveSource()->firstOrFail() still
        // correctly rejects the request afterwards — unchanged.
        RateLimiter::for('legacy-website-form-per-source-ip', function (Request $request): Limit {
            $sourceId = self::resolveLegacyWebsiteFormSourceId($request);

            if ($sourceId === null) {
                return Limit::none();
            }

            return Limit::perMinute(20)->by('legacy-website-form-per-source-ip:'.$sourceId.'|'.$request->ip());
        });

        RateLimiter::for('legacy-website-form-per-source', function (Request $request): Limit {
            $sourceId = self::resolveLegacyWebsiteFormSourceId($request);

            if ($sourceId === null) {
                return Limit::none();
            }

            return Limit::perMinute(60)->by('legacy-website-form-per-source:'.$sourceId);
        });
    }

    /**
     * Mirrors LeadWebhookController::resolveSource()'s own tenant_code →
     * active-Tenant → active-WebsiteForm-source resolution exactly, but
     * with ->first() rather than ->firstOrFail() — a rate limiter has
     * nothing useful to do with a source that doesn't exist or isn't
     * active, and must never be the thing that turns an invalid tenant_code
     * into anything other than the controller's own existing 404/401
     * handling. Deliberately not reused from the controller: this runs in
     * route middleware, before the controller is ever invoked.
     */
    private static function resolveLegacyWebsiteFormSourceId(Request $request): ?string
    {
        $tenantCode = (string) $request->route('tenant_code');

        $tenant = Tenant::where('tenant_code', $tenantCode)
            ->where('status', TenantStatus::Active)
            ->first();

        if ($tenant === null) {
            return null;
        }

        $source = LeadSource::where('tenant_id', $tenant->id)
            ->where('type', LeadSourceType::WebsiteForm)
            ->where('is_active', true)
            ->first();

        return $source?->id;
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
