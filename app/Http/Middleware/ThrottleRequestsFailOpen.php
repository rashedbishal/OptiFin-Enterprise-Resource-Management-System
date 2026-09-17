<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * SEC-2 — a fail-open alternative to Laravel's stock `throttle:` middleware
 * (Illuminate\Routing\Middleware\ThrottleRequests), used only where the
 * approved architecture requires fail-open behavior on a limiter-backend
 * failure. Stock `throttle:` calls `RateLimiter::tooManyAttempts()`/`hit()`
 * with no try/catch anywhere in its class — if the underlying cache store
 * throws (e.g. the `database` cache driver hitting a downed connection),
 * that exception propagates uncaught to a 500, which is neither fail-open
 * nor a clean 429. This middleware exists solely to close that gap for the
 * one named limiter it's given — it is not a generalized rate-limiting
 * framework, and only ever evaluates exactly ONE Limit per invocation (this
 * app's own legacy-website-form limiters, like the builder route's existing
 * `website-form-per-form`/`website-form-per-tenant` pair, are each
 * registered and attached as a separate middleware entry — never as one
 * closure returning an array of Limits).
 *
 * Deliberately narrow: only the two calls that touch the cache backend
 * (tooManyAttempts()/hit()) are inside a try/catch. Resolving the named
 * limiter, invoking its closure, and — critically — $next($request) itself
 * all sit outside that try/catch, so a failure anywhere else on this
 * request (authentication, validation, Lead creation, event dispatch, an
 * unrelated database error) is never misclassified as a limiter failure and
 * never swallowed.
 *
 * Two distinct failure classes are logged at two different levels:
 *   - the named limiter isn't registered at all (a deployment/config
 *     mistake, not a transient outage) → logged at error level, since a
 *     silently-missing limiter defeats the whole point of this fix and
 *     deserves loud visibility;
 *   - the limiter's own cache operations throw (a genuine backend
 *     failure) → logged at warning level.
 * Both fail open — the request is never rejected because the rate limiter
 * itself couldn't be evaluated.
 */
class ThrottleRequestsFailOpen
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next, string $limiterName): Response
    {
        $limiterCallback = $this->limiter->limiter($limiterName);

        if ($limiterCallback === null) {
            Log::error('SEC-2: named rate limiter is not registered — failing open.', [
                'limiter' => $limiterName,
            ]);

            return $next($request);
        }

        $limit = $limiterCallback($request);

        if ($limit instanceof Unlimited) {
            return $next($request);
        }

        if (! $limit instanceof Limit) {
            Log::error('SEC-2: named rate limiter did not return a single Limit as expected — failing open.', [
                'limiter' => $limiterName,
            ]);

            return $next($request);
        }

        try {
            $tooManyAttempts = $this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts);
        } catch (Throwable $e) {
            Log::warning('SEC-2: rate limiter backend failed while checking the request count — failing open.', [
                'limiter' => $limiterName,
                'exception' => $e->getMessage(),
            ]);

            return $next($request);
        }

        if ($tooManyAttempts) {
            return response()->json([
                'status' => 'error',
                'message' => 'Too many requests.',
            ], 429);
        }

        try {
            $this->limiter->hit($limit->key, $limit->decaySeconds);
        } catch (Throwable $e) {
            Log::warning('SEC-2: rate limiter backend failed while recording this request — failing open.', [
                'limiter' => $limiterName,
                'exception' => $e->getMessage(),
            ]);
        }

        return $next($request);
    }
}
