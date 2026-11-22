<?php

namespace App\Http\Controllers\Platform;

use App\Enums\MonitoringTool;
use App\Enums\PermissionSlug;
use App\Http\Controllers\Controller;
use App\Models\PlatformUser;
use App\Services\Logs\LogReader;
use App\Support\Logs\LogFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Downloads for Admin → Monitoring → Logs: one log file, or a folder of them
 * as a zip. Streamed by the web server instead of through Livewire, so a big
 * log file never has to fit in a page response. Needs `update` on the Logs
 * module; files are looked up by id from the listing, never by a path.
 */
class LogDownloadController extends Controller
{
    public function file(LogReader $reader, string $id): BinaryFileResponse
    {
        $this->authorizeUpdate();

        $file = $reader->find($id);
        abort_if($file === null, 404);

        return response()->download($file->path, $file->name, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function folder(LogReader $reader, string $folder): BinaryFileResponse
    {
        $this->authorizeUpdate();

        $files = array_values(array_filter($reader->files(), fn (LogFile $file): bool => $folder !== '' && ($file->folder === $folder || str_starts_with($file->folder, $folder.DIRECTORY_SEPARATOR))));
        abort_if($files === [], 404);

        $zipPath = storage_path('app/private/log-download-'.Str::random(12).'.zip');
        $zip = new ZipArchive;
        abort_unless($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500);

        foreach ($files as $file) {
            $zip->addFile($file->path, $file->relativePath);
        }

        $zip->close();

        return response()->download($zipPath, Str::slug(str_replace(DIRECTORY_SEPARATOR, '-', $folder)).'-logs.zip')->deleteFileAfterSend();
    }

    private function authorizeUpdate(): void
    {
        $admin = Auth::guard('platform')->user();

        abort_unless($admin instanceof PlatformUser && $admin->canUseMonitoringTool(MonitoringTool::Logs, PermissionSlug::Update), 403);
    }
}
