<?php

namespace App\Services;

use App\Notifications\ErrorAlertNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * "New kind of error" emails, the free in-house alternative to an error
 * tracker. Hooked into exception reporting (bootstrap/app.php), so it sees
 * every error Laravel reports — web pages, commands and background jobs —
 * and none it already ignores (not found, validation, not logged in…).
 *
 * - Same kind = same exception class + the same place in the app's OWN code
 *   (the first stack frame inside app/ or routes/, so errors thrown deep in a
 *   library are grouped by the app code that triggered them).
 * - At most one email per kind per day while it keeps happening; each email
 *   says how many times it has happened so far.
 * - Sent to the Monitoring alerts recipients (or OPS_ALERT_EMAIL) through
 *   OpsAlertNotifier, which never throws. Does nothing when neither is set.
 *
 * It must never break the request that hit the error: everything is guarded,
 * and it doesn't re-enter itself if sending the email fails.
 */
class ErrorAlertNotifier
{
    private const EMAIL_EVERY_SECONDS = 86400;

    /**
     * How long an error kind's counter is remembered after it last happened.
     */
    private const COUNT_FOR_DAYS = 30;

    private const TRACE_LINES = 10;

    private bool $handling = false;

    public function __construct(private readonly OpsAlertNotifier $notifier) {}

    public function handle(Throwable $exception): void
    {
        if ($this->handling || ! $this->notifier->isConfigured()) {
            return;
        }

        $this->handling = true;

        try {
            $location = $this->location($exception);
            $key = 'error-alerts:'.sha1($exception::class.'|'.$location);

            Cache::add($key.':count', 0, now()->addDays(self::COUNT_FOR_DAYS));
            $occurrences = (int) Cache::increment($key.':count');

            // Atomic: only the first report in each 24-hour window sends.
            if (! Cache::add($key.':emailed', true, self::EMAIL_EVERY_SECONDS)) {
                return;
            }

            $this->notifier->sendNotification(new ErrorAlertNotification([
                'type' => $exception::class,
                'message' => Str::limit($exception->getMessage() !== '' ? $exception->getMessage() : '—', 500),
                'location' => $location,
                'where' => $this->where(),
                'time' => now()->toDayDateTimeString().' (UTC)',
                'occurrences' => $occurrences,
                'trace' => array_slice(explode("\n", $exception->getTraceAsString()), 0, self::TRACE_LINES),
                'first' => $occurrences === 1,
            ]));
        } catch (Throwable) {
            // Cache or mail trouble must never turn one error into two.
        } finally {
            $this->handling = false;
        }
    }

    /**
     * "app/Services/WhatsAppSender.php:42" — the first frame inside the app's
     * own code, else where the exception was thrown.
     */
    public function location(Throwable $exception): string
    {
        $frames = [['file' => $exception->getFile(), 'line' => $exception->getLine()], ...$exception->getTrace()];

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;

            if (is_string($file) && (str_starts_with($file, app_path()) || str_starts_with($file, base_path('routes')))) {
                return $this->relative($file).':'.($frame['line'] ?? 0);
            }
        }

        return $this->relative($exception->getFile()).':'.$exception->getLine();
    }

    private function where(): string
    {
        if (app()->runningInConsole()) {
            $argv = $_SERVER['argv'] ?? [];

            return 'php '.implode(' ', is_array($argv) ? array_map('strval', $argv) : []);
        }

        $request = request();

        return $request->method().' '.Str::limit($request->fullUrl(), 300);
    }

    private function relative(string $file): string
    {
        return ltrim(Str::after($file, base_path()), '/');
    }
}
