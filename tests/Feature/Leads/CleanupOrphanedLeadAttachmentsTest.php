<?php

use App\Models\LeadAttachment;
use Illuminate\Support\Facades\Storage;

test('an orphaned attachment older than the grace period is deleted', function () {
    Storage::fake('local');

    $path = 'leads/TEN-000001/messenger/orphan_123.jpg';
    Storage::disk('local')->put($path, 'stale-bytes');
    touch(Storage::disk('local')->path($path), now()->subHours(2)->timestamp);

    $this->artisan('leads:cleanup-attachments')->assertExitCode(0);

    Storage::disk('local')->assertMissing($path);
});

test('a file still referenced by a lead attachment is kept', function () {
    Storage::fake('local');

    $attachment = LeadAttachment::factory()->create(['path' => 'leads/TEN-000001/messenger/current_123.jpg']);
    Storage::disk('local')->put($attachment->path, 'current-bytes');
    touch(Storage::disk('local')->path($attachment->path), now()->subHours(2)->timestamp);

    $this->artisan('leads:cleanup-attachments')->assertExitCode(0);

    Storage::disk('local')->assertExists($attachment->path);
});

test('an orphaned file within the grace period is kept, in case a download is still in progress', function () {
    Storage::fake('local');

    $path = 'leads/TEN-000001/messenger/in_progress_123.jpg';
    Storage::disk('local')->put($path, 'in-progress-bytes');

    $this->artisan('leads:cleanup-attachments')->assertExitCode(0);

    Storage::disk('local')->assertExists($path);
});

test('running the command with no leads directory does not error', function () {
    Storage::fake('local');

    $this->artisan('leads:cleanup-attachments')->assertExitCode(0);
});
