<?php

use App\Enums\LeadAttachmentStatus;
use App\Events\LeadUpdated;
use App\Jobs\DownloadLeadAttachment;
use App\Models\Lead;
use App\Models\LeadAttachment;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
    $lead->source->update(['config' => ['access_token' => 'page-token']]);
    $attachment = pendingAttachment($lead);

    Http::fake(['scontent.xx.fbcdn.net/*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

    (new DownloadLeadAttachment(
        attachment: $attachment,
        senderId: 'psid-1',
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
    $lead->source->update(['config' => ['access_token' => 'page-token']]);
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
    $lead->source->update(['config' => ['access_token' => 'page-token']]);
    $attachment = pendingAttachment($lead);

    Http::fake([
        'scontent.xx.fbcdn.net/photo.jpg*' => Http::sequence()
            ->push('unauthorized', 401)
            ->push('fake-image-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    (new DownloadLeadAttachment(
        attachment: $attachment,
        senderId: 'psid-1',
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
    $lead->source->update(['config' => ['access_token' => 'page-token']]);
    $attachment = pendingAttachment($lead);

    Http::fake();

    (new DownloadLeadAttachment(
        attachment: $attachment,
        senderId: 'psid-1',
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
    $lead->source->update(['config' => ['access_token' => 'page-token']]);
    $attachment = pendingAttachment($lead);

    Http::fake(['scontent.xx.fbcdn.net/*' => Http::response(
        str_repeat('a', 25 * 1024 * 1024 + 1),
        200,
        ['Content-Type' => 'image/jpeg'],
    )]);

    (new DownloadLeadAttachment(
        attachment: $attachment,
        senderId: 'psid-1',
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
    $lead->source->update(['config' => ['access_token' => 'wa-token', 'phone_number_id' => '999']]);
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
        platform: 'whatsapp',
        mediaId: 'media-123',
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
    $lead->source->update(['config' => ['access_token' => 'wa-token']]);
    $attachment = pendingAttachment($lead);

    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Unsupported media id']], 400)]);

    (new DownloadLeadAttachment(
        attachment: $attachment,
        senderId: '8801700000000',
        platform: 'whatsapp',
        mediaId: 'bad-id',
    ))->handle();

    $attachment->refresh();

    expect($attachment->status)->toBe(LeadAttachmentStatus::Failed);
    expect($attachment->failure_reason)->toContain('HTTP 400');
});

test('the queued job never carries the raw access token or whatsapp phone number id in its serialized payload', function () {
    Queue::fake();

    $sentinelToken = 'sentinel-token-'.Str::random(12);
    $sentinelPhoneId = 'sentinel-phone-'.Str::random(12);

    $lead = Lead::factory()->create();
    $lead->source->update(['config' => ['access_token' => $sentinelToken, 'phone_number_id' => $sentinelPhoneId]]);
    $attachment = pendingAttachment($lead);

    DownloadLeadAttachment::dispatch(
        attachment: $attachment,
        senderId: '8801700000000',
        platform: 'whatsapp',
        mediaId: 'media-123',
    );

    Queue::assertPushed(DownloadLeadAttachment::class, function (DownloadLeadAttachment $job) use ($sentinelToken, $sentinelPhoneId): bool {
        expect(property_exists($job, 'accessToken'))->toBeFalse();
        expect(property_exists($job, 'whatsappPhoneNumberId'))->toBeFalse();

        $serialized = serialize($job);
        expect($serialized)->not->toContain($sentinelToken);
        expect($serialized)->not->toContain($sentinelPhoneId);

        return true;
    });
});

test('the job resolves the LeadSource credential fresh at execution time, even if it was rotated after the job was constructed', function () {
    Storage::fake('local');

    $lead = Lead::factory()->create();
    $lead->source->update(['config' => ['access_token' => 'stale-token']]);
    $attachment = pendingAttachment($lead);

    $job = new DownloadLeadAttachment(
        attachment: $attachment,
        senderId: 'psid-1',
        platform: 'facebook_messenger',
        remoteUrl: 'https://scontent.xx.fbcdn.net/photo.jpg',
    );

    // Credential rotated after the job object was built but before it ran —
    // exactly the window this fix is meant to keep safe from stale in-memory
    // token reuse.
    $lead->source->update(['config' => ['access_token' => 'rotated-token']]);

    Http::fake([
        'scontent.xx.fbcdn.net/photo.jpg*' => Http::sequence()
            ->push('unauthorized', 401)
            ->push('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $job->handle();

    expect($attachment->fresh()->status)->toBe(LeadAttachmentStatus::Downloaded);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'access_token=rotated-token'));
    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'stale-token'));
});

test('a missing credential at execution time marks the attachment failed instead of throwing', function () {
    Http::fake();

    $lead = Lead::factory()->create();
    $lead->source->update(['config' => []]);
    $attachment = pendingAttachment($lead);

    (new DownloadLeadAttachment(
        attachment: $attachment,
        senderId: 'psid-1',
        platform: 'facebook_messenger',
        remoteUrl: 'https://scontent.xx.fbcdn.net/photo.jpg',
    ))->handle();

    $attachment->refresh();

    expect($attachment->status)->toBe(LeadAttachmentStatus::Failed);
    expect($attachment->failure_reason)->toBe('Lead source credential is no longer configured.');

    Http::assertNothingSent();
});

test('resolving credentials never crosses into another tenant\'s LeadSource', function () {
    Storage::fake('local');

    $leadA = Lead::factory()->create();
    $leadA->source->update(['config' => ['access_token' => 'tenant-a-token']]);
    $attachmentA = pendingAttachment($leadA);

    $leadB = Lead::factory()->create();
    $leadB->source->update(['config' => ['access_token' => 'tenant-b-token']]);
    pendingAttachment($leadB);

    Http::fake([
        'scontent.xx.fbcdn.net/photo.jpg*' => Http::sequence()
            ->push('unauthorized', 401)
            ->push('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    (new DownloadLeadAttachment(
        attachment: $attachmentA,
        senderId: 'psid-1',
        platform: 'facebook_messenger',
        remoteUrl: 'https://scontent.xx.fbcdn.net/photo.jpg',
    ))->handle();

    expect($attachmentA->fresh()->status)->toBe(LeadAttachmentStatus::Downloaded);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'access_token=tenant-a-token'));
    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'tenant-b-token'));
});
