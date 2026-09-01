<?php

use App\Enums\LeadAttachmentStatus;
use App\Enums\LeadSourceType;
use App\Jobs\DownloadLeadAttachment;
use App\Models\Lead;
use App\Models\LeadSource;
use Illuminate\Support\Facades\Queue;

test('a facebook message with an image attachment and no caption leaves the message empty, creates a pending attachment, and queues a download job', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'page_access_token' => 'page-token', 'verify_token' => 'secret-token'],
    ]);

    $payload = [
        'entry' => [[
            'messaging' => [[
                'sender' => ['id' => 'psid-1'],
                'message' => [
                    'attachments' => [[
                        'type' => 'image',
                        'payload' => ['url' => 'https://scontent.xx.fbcdn.net/photo.jpg'],
                    ]],
                ],
            ]],
        ]],
    ];

    Queue::fake();

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), $payload)->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->message)->toBeNull();

    $attachment = $lead->attachments()->sole();
    expect($attachment->type)->toBe('image');
    expect($attachment->status)->toBe(LeadAttachmentStatus::Downloading);

    Queue::assertPushed(DownloadLeadAttachment::class, fn (DownloadLeadAttachment $job): bool => $job->attachment->is($attachment)
        && $job->platform === 'facebook_messenger'
        && $job->senderId === 'psid-1'
        && $job->accessToken === 'page-token'
        && $job->remoteUrl === 'https://scontent.xx.fbcdn.net/photo.jpg');
});

test('a facebook message with five attachments creates five pending attachments and queues five download jobs', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'page_access_token' => 'page-token', 'verify_token' => 'secret-token'],
    ]);

    $payload = [
        'entry' => [[
            'messaging' => [[
                'sender' => ['id' => 'psid-1'],
                'message' => [
                    'attachments' => array_map(
                        fn (int $i): array => ['type' => 'image', 'payload' => ['url' => "https://scontent.xx.fbcdn.net/photo-{$i}.jpg"]],
                        range(1, 5),
                    ),
                ],
            ]],
        ]],
    ];

    Queue::fake();

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), $payload)->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();

    expect($lead->attachments)->toHaveCount(5);
    expect($lead->attachments->pluck('status')->unique()->all())->toBe([LeadAttachmentStatus::Downloading]);

    Queue::assertPushed(DownloadLeadAttachment::class, 5);

    foreach (range(1, 5) as $i) {
        Queue::assertPushed(DownloadLeadAttachment::class, fn (DownloadLeadAttachment $job): bool => $job->remoteUrl === "https://scontent.xx.fbcdn.net/photo-{$i}.jpg");
    }
});

test('an instagram dm with a video attachment and caption text tags the lead message and queues a download job', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Instagram,
        'config' => ['auth_mode' => 'instagram', 'instagram_account_id' => '789', 'access_token' => 'igaa-token', 'verify_token' => 'secret-token'],
    ]);

    $payload = [
        'entry' => [[
            'messaging' => [[
                'sender' => ['id' => 'igsid-1'],
                'message' => [
                    'text' => 'Check this out',
                    'attachments' => [[
                        'type' => 'video',
                        'payload' => ['url' => 'https://scontent.xx.cdninstagram.com/clip.mp4'],
                    ]],
                ],
            ]],
        ]],
    ];

    Queue::fake();

    $this->postJson(route('api.webhook.instagram', $tenant->tenant_code), $payload)->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->message)->toBe('Check this out');

    $attachment = $lead->attachments()->sole();
    expect($attachment->type)->toBe('video');

    Queue::assertPushed(DownloadLeadAttachment::class, fn (DownloadLeadAttachment $job): bool => $job->attachment->is($attachment)
        && $job->platform === 'instagram'
        && $job->accessToken === 'igaa-token'
        && $job->remoteUrl === 'https://scontent.xx.cdninstagram.com/clip.mp4');
});

test('a whatsapp image message queues a download job keyed by media id instead of a url', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '456', 'access_token' => 'wa-token', 'verify_token' => 'secret'],
    ]);

    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'from' => '8801700000000',
                        'type' => 'image',
                        'image' => ['id' => 'media-abc', 'caption' => 'Our new product'],
                    ]],
                ],
            ]],
        ]],
    ];

    Queue::fake();

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->message)->toBe('Our new product');

    $attachment = $lead->attachments()->sole();
    expect($attachment->type)->toBe('image');

    Queue::assertPushed(DownloadLeadAttachment::class, fn (DownloadLeadAttachment $job): bool => $job->attachment->is($attachment)
        && $job->platform === 'whatsapp'
        && $job->mediaId === 'media-abc'
        && $job->accessToken === 'wa-token'
        && $job->whatsappPhoneNumberId === '456'
        && $job->remoteUrl === null);
});

test('an attachment is ignored without crashing when the source has no access token configured', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'verify_token' => 'secret-token'],
    ]);

    $payload = [
        'entry' => [[
            'messaging' => [[
                'sender' => ['id' => 'psid-1'],
                'message' => [
                    'attachments' => [[
                        'type' => 'image',
                        'payload' => ['url' => 'https://scontent.xx.fbcdn.net/photo.jpg'],
                    ]],
                ],
            ]],
        ]],
    ];

    Queue::fake();

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), $payload)->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->attachments()->count())->toBe(0);
    Queue::assertNotPushed(DownloadLeadAttachment::class);
});

test('a whatsapp payload with three image messages from the same new sender creates one lead with three pending attachments and queues three download jobs', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '456', 'access_token' => 'wa-token', 'verify_token' => 'secret'],
    ]);

    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'messages' => array_map(
                        fn (int $i): array => [
                            'from' => '8801700000000',
                            'type' => 'image',
                            'image' => ['id' => "media-{$i}", 'caption' => "Photo {$i}"],
                        ],
                        range(1, 3),
                    ),
                ],
            ]],
        ]],
    ];

    Queue::fake();

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->sole();

    expect($lead->attachments)->toHaveCount(3);
    expect($lead->attachments->pluck('status')->unique()->all())->toBe([LeadAttachmentStatus::Downloading]);
    expect($lead->source_data['messages'])->toHaveCount(3);
    expect($lead->message)->toBe("Photo 1\nPhoto 2\nPhoto 3");

    Queue::assertPushed(DownloadLeadAttachment::class, 3);

    foreach (range(1, 3) as $i) {
        Queue::assertPushed(DownloadLeadAttachment::class, fn (DownloadLeadAttachment $job): bool => $job->mediaId === "media-{$i}");
    }
});
