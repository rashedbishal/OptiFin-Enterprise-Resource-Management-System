<?php

use App\Enums\LeadAttachmentStatus;
use App\Events\LeadUpdated;
use App\Jobs\DownloadLeadAttachment;
use App\Models\Lead;
use App\Models\LeadAttachment;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * A pending row, as LeadWebhookController::createPendingAttachmentAndDispatch()
 * would create before dispatching the job.
 */
function pendingAttachment(Lead $lead, string $type = 'image'): LeadAttachment
{
    return $lead->attachments()->create([
        'type' => $type,
        'status' => LeadAttachmentStatus::Downloading,
        'sent_at' => now(),
    ]);
}

test('downloading a messenger attachment fills in the pending record', function () {
    Storage::fake('local');
    Event::fake([LeadUpdated::class]);

    $lead = Lead::factory()->create();
    $attachment = pendingAttachment($lead);

    Http::fake(['scontent.xx.fbcdn.net/*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

    (new DownloadLeadAttachment(
        attachment: $attachment,
        senderId: 'psid-1',
        accessToken: 'page-token',
        platform: 'facebook_messenger',
        remoteUrl: 'https://scontent.xx.fbcdn.net/photo.jpg',
    ))->handle();

    $attachment->refresh();

    expect($attachment->status)->toBe(LeadAttachmentStatus::Downloaded);
    expect($attachment->failure_reason)->toBeNull();
    expect($attachment->mime)->toBe('image/jpeg');
    expect($attachment->original_media_url)->toBe('https://scontent.xx.fbcdn.net/photo.jpg');
    expect($attachment->path)->toStartWith("leads/{$lead->tenant->tenant_code}/messenger/psid-1_");
    expect($attachment->summary())->toBe('Sent an image');

    Storage::disk('local')->assertExists($attachment->path);
    Event::assertDispatched(LeadUpdated::class, fn (LeadUpdated $event): bool => $event->lead->is($lead));
});

test('summary picks the grammatically correct article for the attachment type', function () {
    expect(LeadAttachment::factory()->make(['type' => 'image'])->summary())->toBe('Sent an image');
    expect(LeadAttachment::factory()->make(['type' => 'video'])->summary())->toBe('Sent a video');
    expect(LeadAttachment::factory()->make(['type' => 'audio'])->summary())->toBe('Sent an audio');
    expect(LeadAttachment::factory()->make(['type' => 'document'])->summary())->toBe('Sent a document');
});

test('a lead can accumulate multiple attachments from the same message, each with its own working record', function () {
    Storage::fake('local');

    $lead = Lead::factory()->create();
    $first = pendingAttachment($lead);
    $second = pendingAttachment($lead);

    // A closure fake, not a static Http::response(): the download reads the
    // response body via a sink, which consumes a PSR-7 stream's contents on
    // first read — a single shared Http::response() would leave the second
    // download's sink empty, unlike real per-request HTTP bodies.
    Http::fake(['scontent.xx.fbcdn.net/*' => fn () => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

    foreach ([$first, $second] as $attachment) {
        (new DownloadLeadAttachment(
            attachment: $attachment,
            senderId: 'psid-1',
            accessToken: 'page-token',
            platform: 'facebook_messenger',
            remoteUrl: 'https://scontent.xx.fbcdn.net/photo.jpg',
        ))->handle();
    }

    $paths = $lead->attachments()->pluck('path');

    expect($paths)->toHaveCount(2);
    expect($paths->unique())->toHaveCount(2);
    expect($lead->attachments()->where('status', LeadAttachmentStatus::Downloaded)->count())->toBe(2);

    foreach ($paths as $path) {
        Storage::disk('local')->assertExists($path);
    }
});

test('a messenger attachment download retries with the access token appended if the unauthenticated attempt fails', function () {
    Storage::fake('local');

    $lead = Lead::factory()->create();
    $attachment = pendingAttachment($lead);

    Http::fake([
        'scontent.xx.fbcdn.net/photo.jpg*' => Http::sequence()
            ->push('unauthorized', 401)
            ->push('fake-image-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    (new DownloadLeadAttachment(
        attachment: $attachment,
        senderId: 'psid-1',
        accessToken: 'page-token',
        platform: 'facebook_messenger',
        remoteUrl: 'https://scontent.xx.fbcdn.net/photo.jpg',
    ))->handle();

    expect($attachment->fresh()->status)->toBe(LeadAttachmentStatus::Downloaded);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'access_token=page-token'));
});

test('a download from a host outside the allow-list is marked failed without making any request', function () {
    Storage::fake('local');
    Event::fake([LeadUpdated::class]);

    $lead = Lead::factory()->create();
    $attachment = pendingAttachment($lead);

    Http::fake();

    (new DownloadLeadAttachment(
        attachment: $attachment,
        senderId: 'psid-1',
        accessToken: 'page-token',
        platform: 'facebook_messenger',
        remoteUrl: 'https://internal.example.com/secrets.jpg',
    ))->handle();

    $attachment->refresh();

    expect($attachment->status)->toBe(LeadAttachmentStatus::Failed);
    expect($attachment->failure_reason)->toContain('Disallowed host');
    expect($attachment->path)->toBeNull();

    Http::assertNothingSent();
    Event::assertNotDispatched(LeadUpdated::class);
});

test('an attachment over the platform size cap is marked failed and not saved', function () {
    Storage::fake('local');

    $lead = Lead::factory()->create();
    $attachment = pendingAttachment($lead);

    Http::fake(['scontent.xx.fbcdn.net/*' => Http::response(
        str_repeat('a', 25 * 1024 * 1024 + 1),
        200,
        ['Content-Type' => 'image/jpeg'],
    )]);

    (new DownloadLeadAttachment(
        attachment: $attachment,
        senderId: 'psid-1',
        accessToken: 'page-token',
        platform: 'facebook_messenger',
        remoteUrl: 'https://scontent.xx.fbcdn.net/photo.jpg',
    ))->handle();

    $attachment->refresh();

    expect($attachment->status)->toBe(LeadAttachmentStatus::Failed);
    expect($attachment->failure_reason)->toContain('bytes');
    expect($attachment->path)->toBeNull();
});

test('downloading whatsapp media resolves the media url via the graph api then downloads it with a bearer token', function () {
    Storage::fake('local');

    $lead = Lead::factory()->create();
    $attachment = pendingAttachment($lead, 'audio');

    Http::fake([
        'graph.facebook.com/v21.0/media-123*' => Http::response([
            'url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?token',
            'mime_type' => 'audio/ogg; codecs=opus',
            'id' => 'media-123',
        ]),
        'lookaside.fbsbx.com/*' => Http::response('fake-audio-bytes', 200),
    ]);

    (new DownloadLeadAttachment(
        attachment: $attachment,
        senderId: '8801700000000',
        accessToken: 'wa-token',
        platform: 'whatsapp',
        mediaId: 'media-123',
        whatsappPhoneNumberId: '999',
    ))->handle();

    $attachment->refresh();

    expect($attachment->status)->toBe(LeadAttachmentStatus::Downloaded);
    expect($attachment->mime)->toBe('audio/ogg');
    Storage::disk('local')->assertExists($attachment->path);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'graph.facebook.com/v21.0/media-123')
        && $request->hasHeader('Authorization', 'Bearer wa-token')
        && $request['phone_number_id'] === '999');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'lookaside.fbsbx.com')
        && $request->hasHeader('Authorization', 'Bearer wa-token'));
});

test('a failed whatsapp media lookup marks the attachment failed instead of throwing', function () {
    Storage::fake('local');

    $lead = Lead::factory()->create();
    $attachment = pendingAttachment($lead);

    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Unsupported media id']], 400)]);

    (new DownloadLeadAttachment(
        attachment: $attachment,
        senderId: '8801700000000',
        accessToken: 'wa-token',
        platform: 'whatsapp',
        mediaId: 'bad-id',
    ))->handle();

    $attachment->refresh();

    expect($attachment->status)->toBe(LeadAttachmentStatus::Failed);
    expect($attachment->failure_reason)->toContain('HTTP 400');
});
