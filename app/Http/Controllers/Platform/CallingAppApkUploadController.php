<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformUser;
use App\Services\CallingAppRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Admin → Calling app: the chunked APK upload (start → chunks → complete),
 * driven by resources/js/calling-app-apk-upload.js. Mother Admin only, like
 * everything else on that page. Each chunk is a raw request body of at most
 * CallingAppRelease::CHUNK_BYTES, so no PHP/nginx upload limit is involved.
 */
class CallingAppApkUploadController extends Controller
{
    public function start(Request $request, CallingAppRelease $releases): JsonResponse
    {
        $this->motherAdmin($request);

        $validated = $request->validate([
            'size' => ['required', 'integer', 'min:1', 'max:'.CallingAppRelease::MAX_BYTES],
            'version' => ['required', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! CallingAppRelease::isValidVersion($value)) {
                    $fail(__('Use a version like 1.3.0.'));
                }
            }],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $id = $releases->startUpload((int) $validated['size'], $validated['version'], $validated['notes'] ?? null);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage());
        }

        return response()->json(['data' => ['upload_id' => $id, 'chunk_bytes' => CallingAppRelease::CHUNK_BYTES]]);
    }

    public function chunk(Request $request, CallingAppRelease $releases, string $upload, int $index): JsonResponse
    {
        $this->motherAdmin($request);

        try {
            $releases->putChunk($upload, $index, $request->getContent());
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage());
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    public function complete(Request $request, CallingAppRelease $releases, string $upload): JsonResponse
    {
        $actor = $this->motherAdmin($request);

        try {
            $release = $releases->completeUpload($upload, $actor);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage());
        }

        return response()->json(['data' => $release]);
    }

    private function motherAdmin(Request $request): PlatformUser
    {
        $actor = $request->user('platform');

        abort_unless($actor instanceof PlatformUser && $actor->isMotherAdmin(), 403);

        return $actor;
    }

    private function error(string $message): JsonResponse
    {
        return response()->json(['error' => ['message' => $message]], 422);
    }
}
