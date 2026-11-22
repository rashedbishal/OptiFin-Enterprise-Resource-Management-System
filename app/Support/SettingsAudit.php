<?php

namespace App\Support;

use App\Models\PlatformUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records changes to the Mother Admin's sensitive settings (Email settings,
 * Backups, Calling app) in the activity log, shown at Admin → Settings →
 * Activity log. Only names and non-secret values — never a password, key or
 * token. Best-effort: a logging problem never stops the setting being saved.
 */
class SettingsAudit
{
    public const LOG_NAME = 'settings';

    /**
     * @param  string  $area  e.g. "Email settings — User emails"
     * @param  string  $action  "saved" | "removed" | "published"
     * @param  array<string, scalar|null>  $details  non-secret facts, e.g. ['host' => 'smtp.gmail.com', 'password changed' => true]
     */
    public static function record(string $area, string $action, array $details = [], ?PlatformUser $by = null): void
    {
        try {
            $actor = $by ?? Auth::guard('platform')->user();

            $activity = activity(self::LOG_NAME)
                ->event($action)
                ->withProperties(['area' => $area, 'details' => $details]);

            if ($actor instanceof PlatformUser) {
                $activity->causedBy($actor);
            }

            $activity->log("{$area} {$action}");
        } catch (Throwable $exception) {
            Log::warning('A settings change could not be written to the activity log.', ['area' => $area, 'exception' => $exception::class]);
        }
    }

    /**
     * Field names whose non-secret values differ, with their new values;
     * secret fields are only reported as changed or not.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     * @param  list<string>  $secretFields
     * @return array<string, scalar|null>
     */
    public static function changes(?array $before, array $after, array $secretFields): array
    {
        $details = [];

        foreach ($after as $field => $value) {
            $old = $before[$field] ?? null;

            if (in_array($field, $secretFields, true)) {
                if ($old !== $value) {
                    $details["{$field} changed"] = true;
                }

                continue;
            }

            if ($old !== $value) {
                $details[$field] = is_array($value) ? implode(', ', array_map('strval', $value)) : (is_scalar($value) || $value === null ? $value : null);
            }
        }

        return $details;
    }
}
