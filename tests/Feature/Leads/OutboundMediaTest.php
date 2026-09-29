<?php

use App\Enums\LeadSourceType;
use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Jobs\SendOutboundMessage;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Message;
use App\Services\MessageSenderFactory;
use App\Services\OutboundEligibilityService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake('local'));

/**
 * @param  array<string, mixed>  $config
 * @return array{tenant: mixed, admin: mixed, lead: Lead, source: LeadSource, conversation: Conversation}
 */
function mediaLead(LeadSourceType $type = LeadSourceType::Whatsapp, array $config = [], bool $windowOpen = true): array
{
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => $type,
        'config' => $config !== [] ? $config : match ($type) {
            LeadSourceType::Whatsapp => ['phone_number_id' => '1', 'access_token' => 'token'],
            LeadSourceType::FacebookMessenger => ['page_access_token' => 'token'],
            default => ['access_token' => 'token'],
        },
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id,
        'channel' => MessageChannel::fromLeadSourceType($type), 'last_inbound_at' => $windowOpen ? now() : now()->subDays(3),
    ]);

    return compact('tenant', 'admin', 'lead', 'source', 'conversation');
}

function fakePdf(int $bytes = 2048, string $name = 'quote.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".str_repeat('a', $bytes)."\n%%EOF");
}

function fakeGif(string $name = 'anim.gif'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
}

function fakeJpegOfSize(int $bytes, string $name = 'big.jpg'): UploadedFile
{
    $seed = UploadedFile::fake()->image('seed.jpg', 8, 8);
    $jpeg = (string) file_get_contents($seed->getRealPath());

    return UploadedFile::fake()->createWithContent($name, $jpeg.str_repeat("\0", max(0, $bytes - strlen($jpeg))));
}

function composer(mixed $admin, Lead $lead)
{
    return Livewire::actingAs($admin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad');
}

// ---------------------------------------------------------------- sending

test('whatsapp: an image is uploaded then sent by media id, the text riding along as its caption', function () {
    ['admin' => $admin, 'lead' => $lead, 'tenant' => $tenant] = mediaLead();
    Http::fake([
        'graph.facebook.com/v20.0/1/media' => Http::response(['id' => 'MEDIA1'], 200),
        'graph.facebook.com/v20.0/1/messages' => Http::response(['messages' => [['id' => 'wamid.IMG']]], 200),
    ]);

    composer($admin, $lead)
        ->set('imageUpload', UploadedFile::fake()->image('photo.jpg', 40, 40))
        ->set('newMessageBody', 'Here is the photo')
        ->call('sendMessage')
        ->assertHasNoErrors()
        ->assertSet('imageUpload', null)
        ->assertSet('newMessageBody', '');

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/1/media') && $r->isMultipart() && $r->hasHeader('Authorization', 'Bearer token'));
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/1/messages')
        && $r['type'] === 'image'
        && $r['image'] === ['id' => 'MEDIA1', 'caption' => 'Here is the photo']);

    $message = Message::where('lead_id', $lead->id)->sole();
    expect($message->message_type)->toBe(MessageType::Image)
        ->and($message->body)->toBe('Here is the photo')
        ->and($message->status)->toBe(MessageStatus::Sent)
        ->and($message->external_message_id)->toBe('wamid.IMG')
        ->and($message->sent_by)->toBe($admin->id)
        ->and($message->attachment_mime)->toBe('image/jpeg')
        ->and($message->attachment_name)->toBe('photo.jpg')
        ->and($message->attachment_path)->toStartWith("outbound/{$tenant->tenant_code}/whatsapp/")->toEndWith('.jpg');
    Storage::disk('local')->assertExists($message->attachment_path);
});

test('whatsapp: a pdf is sent as a document with its filename and no caption when there is no text', function () {
    ['admin' => $admin, 'lead' => $lead] = mediaLead();
    Http::fake([
        'graph.facebook.com/v20.0/1/media' => Http::response(['id' => 'MEDIA2'], 200),
        'graph.facebook.com/v20.0/1/messages' => Http::response(['messages' => [['id' => 'wamid.DOC']]], 200),
    ]);

    composer($admin, $lead)->set('pdfUpload', fakePdf())->call('sendMessage')->assertHasNoErrors();

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/1/messages')
        && $r['type'] === 'document'
        && $r['document'] === ['id' => 'MEDIA2', 'filename' => 'quote.pdf']);

    $message = Message::where('lead_id', $lead->id)->sole();
    expect($message->message_type)->toBe(MessageType::Document)->and($message->body)->toBeNull()->and($message->attachment_mime)->toBe('application/pdf');
});

test('messenger: an image with text becomes two messages, the text first, and the file goes through message_attachments', function () {
    ['admin' => $admin, 'lead' => $lead] = mediaLead(LeadSourceType::FacebookMessenger);
    Http::fake([
        'graph.facebook.com/v20.0/me/message_attachments' => Http::response(['attachment_id' => 'ATT1'], 200),
        'graph.facebook.com/v20.0/me/messages' => Http::sequence()
            ->push(['message_id' => 'm.text'], 200)
            ->push(['message_id' => 'm.file'], 200),
    ]);

    composer($admin, $lead)
        ->set('imageUpload', fakeGif())
        ->set('newMessageBody', 'See attached')
        ->call('sendMessage')
        ->assertHasNoErrors();

    $messages = Message::where('lead_id', $lead->id)->orderBy('created_at')->orderBy('id')->get();
    expect($messages)->toHaveCount(2);

    $text = $messages->firstWhere('message_type', MessageType::Text);
    $file = $messages->firstWhere('message_type', MessageType::Image);
    expect($text->body)->toBe('See attached')->and($text->status)->toBe(MessageStatus::Sent)->and($text->external_message_id)->toBe('m.text');
    expect($file->body)->toBeNull()->and($file->status)->toBe(MessageStatus::Sent)->and($file->external_message_id)->toBe('m.file');

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/me/message_attachments') && $r->isMultipart());
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/me/messages')
        && ($r['message']['attachment']['payload']['attachment_id'] ?? null) === 'ATT1'
        && $r['message']['attachment']['type'] === 'image');
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/me/messages') && ($r['message']['text'] ?? null) === 'See attached');
});

test('messenger: a pdf goes out as a file attachment', function () {
    ['admin' => $admin, 'lead' => $lead] = mediaLead(LeadSourceType::FacebookMessenger);
    Http::fake([
        'graph.facebook.com/v20.0/me/message_attachments' => Http::response(['attachment_id' => 'ATT2'], 200),
        'graph.facebook.com/v20.0/me/messages' => Http::response(['message_id' => 'm.pdf'], 200),
    ]);

    composer($admin, $lead)->set('pdfUpload', fakePdf())->call('sendMessage')->assertHasNoErrors();

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/me/messages') && $r['message']['attachment']['type'] === 'file');
    expect(Message::where('lead_id', $lead->id)->sole()->message_type)->toBe(MessageType::Document);
});

test('instagram: the upload targets the host chosen by the source auth mode and is tagged as instagram', function () {
    ['admin' => $admin, 'lead' => $lead] = mediaLead(LeadSourceType::Instagram, ['access_token' => 'token', 'auth_mode' => 'instagram']);
    Http::fake([
        'graph.instagram.com/v20.0/me/message_attachments' => Http::response(['attachment_id' => 'IG1'], 200),
        'graph.instagram.com/v20.0/me/messages' => Http::response(['message_id' => 'ig.file'], 200),
    ]);

    composer($admin, $lead)->set('pdfUpload', fakePdf())->call('sendMessage')->assertHasNoErrors();

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/me/message_attachments') && collect($r->data())->contains(fn ($part) => ($part['name'] ?? null) === 'platform' && ($part['contents'] ?? null) === 'instagram'));
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/me/messages') && $r['message']['attachment']['payload']['attachment_id'] === 'IG1');
    expect(Message::where('lead_id', $lead->id)->sole()->status)->toBe(MessageStatus::Sent);
});

test('a platform rejection of the upload marks the file message failed with the platform reason and is resendable', function () {
    ['admin' => $admin, 'lead' => $lead] = mediaLead();
    Http::fake([
        'graph.facebook.com/v20.0/1/media' => Http::sequence()->push(['error' => ['message' => 'Media upload failed']], 400)->push(['id' => 'MEDIA9'], 200),
        'graph.facebook.com/v20.0/1/messages' => Http::response(['messages' => [['id' => 'wamid.RESENT']]], 200),
    ]);

    composer($admin, $lead)->set('pdfUpload', fakePdf())->call('sendMessage');

    $failed = Message::where('lead_id', $lead->id)->sole();
    expect($failed->status)->toBe(MessageStatus::Failed)->and($failed->failure_reason)->toBe('Media upload failed');

    composer($admin, $lead)->call('resendMessage', $failed->id)->assertHasNoErrors();

    $resent = Message::where('lead_id', $lead->id)->where('id', '!=', $failed->id)->sole();
    expect($resent->message_type)->toBe(MessageType::Document)
        ->and($resent->attachment_path)->toBe($failed->attachment_path)
        ->and($resent->status)->toBe(MessageStatus::Sent)
        ->and($failed->fresh()->resend_message_id)->toBe($resent->id);
});

test('resending a failed file message whose stored file is gone is refused and creates nothing', function () {
    ['admin' => $admin, 'lead' => $lead, 'tenant' => $tenant, 'conversation' => $conversation] = mediaLead();
    $failed = Message::factory()->outbound()->withStatus(MessageStatus::Failed)->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'conversation_id' => $conversation->id,
        'message_type' => MessageType::Document, 'body' => null,
        'attachment_path' => 'outbound/x/whatsapp/gone.pdf', 'attachment_mime' => 'application/pdf', 'attachment_name' => 'gone.pdf', 'attachment_size' => 10,
    ]);
    Http::fake();

    composer($admin, $lead)->call('resendMessage', $failed->id)->assertDispatched('toast-show');

    expect(Message::where('lead_id', $lead->id)->count())->toBe(1)->and($failed->fresh()->resend_message_id)->toBeNull();
    Http::assertNothingSent();
});

test('the send job marks a file message failed when its stored file has disappeared', function () {
    ['lead' => $lead, 'tenant' => $tenant, 'conversation' => $conversation] = mediaLead();
    $message = Message::factory()->outbound()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'conversation_id' => $conversation->id, 'channel' => MessageChannel::Whatsapp,
        'message_type' => MessageType::Image, 'body' => null,
        'attachment_path' => 'outbound/x/whatsapp/gone.jpg', 'attachment_mime' => 'image/jpeg', 'attachment_name' => 'gone.jpg', 'attachment_size' => 10,
    ]);
    Http::fake();

    (new SendOutboundMessage($message))->handle(app(OutboundEligibilityService::class), app(MessageSenderFactory::class));

    expect($message->fresh()->status)->toBe(MessageStatus::Failed)->and($message->fresh()->failure_reason)->toBe('The attached file is no longer available.');
    Http::assertNothingSent();
});

// ------------------------------------------------------------- validation

test('per-channel type and size limits reject a bad file at pick time and drop it', function (LeadSourceType $type, string $property, Closure $file, string $error) {
    ['admin' => $admin, 'lead' => $lead] = mediaLead($type);

    composer($admin, $lead)
        ->set($property, $file())
        ->assertHasErrors([$property])
        ->assertSet($property, null)
        ->assertSee($error);

    expect(Message::count())->toBe(0);
})->with([
    'whatsapp rejects gif' => [LeadSourceType::Whatsapp, 'imageUpload', fn () => fakeGif(), 'Only JPEG, PNG files can be sent on WhatsApp.'],
    'whatsapp image over 5 MB' => [LeadSourceType::Whatsapp, 'imageUpload', fn () => fakeJpegOfSize(5 * 1024 * 1024 + 2048), 'WhatsApp allows up to 5 MB'],
    'instagram rejects gif' => [LeadSourceType::Instagram, 'imageUpload', fn () => fakeGif(), 'Only JPEG, PNG files can be sent on Instagram.'],
    'instagram image over 8 MB' => [LeadSourceType::Instagram, 'imageUpload', fn () => fakeJpegOfSize(8 * 1024 * 1024 + 2048), 'Instagram allows up to 8 MB'],
    'pdf over 25 MB' => [LeadSourceType::FacebookMessenger, 'pdfUpload', fn () => fakePdf(25 * 1024 * 1024 + 4096), 'Messenger allows up to 25 MB'],
    'a pdf renamed .jpg' => [LeadSourceType::Whatsapp, 'imageUpload', fn () => fakePdf(2048, 'sneaky.jpg'), 'Only JPEG, PNG files can be sent on WhatsApp.'],
    'text renamed .pdf' => [LeadSourceType::Whatsapp, 'pdfUpload', fn () => UploadedFile::fake()->createWithContent('notes.pdf', 'just some text'), 'Only PDF files can be sent on WhatsApp.'],
    'an image sent through the pdf button' => [LeadSourceType::FacebookMessenger, 'pdfUpload', fn () => UploadedFile::fake()->image('photo.png', 10, 10), 'Only PDF files can be sent on Messenger.'],
]);

test('messenger accepts a gif and a 6 MB image that whatsapp would refuse', function () {
    ['admin' => $admin, 'lead' => $lead] = mediaLead(LeadSourceType::FacebookMessenger);

    composer($admin, $lead)->set('imageUpload', fakeGif())->assertHasNoErrors()->set('imageUpload', fakeJpegOfSize(6 * 1024 * 1024))->assertHasNoErrors();
});

test('picking a pdf clears a pending image and vice versa, and remove clears both', function () {
    ['admin' => $admin, 'lead' => $lead] = mediaLead();

    composer($admin, $lead)
        ->set('imageUpload', UploadedFile::fake()->image('a.png', 10, 10))
        ->set('pdfUpload', fakePdf())
        ->assertSet('imageUpload', null)
        ->set('imageUpload', UploadedFile::fake()->image('b.png', 10, 10))
        ->assertSet('pdfUpload', null)
        ->call('removeUpload')
        ->assertSet('imageUpload', null)
        ->assertSet('pdfUpload', null);
});

test('a whatsapp caption over 1024 characters is rejected and nothing is stored or sent', function () {
    ['admin' => $admin, 'lead' => $lead] = mediaLead();
    Http::fake();

    composer($admin, $lead)
        ->set('pdfUpload', fakePdf())
        ->set('newMessageBody', str_repeat('x', 1025))
        ->call('sendMessage')
        ->assertHasErrors(['newMessageBody']);

    Http::assertNothingSent();
    expect(Message::count())->toBe(0)->and(Storage::disk('local')->allFiles('outbound'))->toBe([]);
});

// -------------------------------------------- existing rules still apply

test('an expired messaging window blocks a file send exactly like a text send', function () {
    ['admin' => $admin, 'lead' => $lead] = mediaLead(windowOpen: false);
    Http::fake();

    composer($admin, $lead)->set('pdfUpload', fakePdf())->call('sendMessage')->assertDispatched('toast-show');

    Http::assertNothingSent();
    expect(Message::count())->toBe(0)->and(Storage::disk('local')->allFiles('outbound'))->toBe([]);
});

test('a client user without update permission cannot send a file', function () {
    ['tenant' => $tenant, 'lead' => $lead] = mediaLead();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $lead->update(['assigned_to' => $rep->id]);
    Http::fake();

    Livewire::actingAs($rep)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('pdfUpload', fakePdf())
        ->call('sendMessage');

    Http::assertNothingSent();
    expect(Message::count())->toBe(0);
});

test('a text-only reply is unchanged by the media feature', function () {
    ['admin' => $admin, 'lead' => $lead] = mediaLead();
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.T']]], 200)]);

    composer($admin, $lead)->set('newMessageBody', 'Plain text')->call('sendMessage')->assertHasNoErrors();

    $message = Message::where('lead_id', $lead->id)->sole();
    expect($message->message_type)->toBe(MessageType::Text)->and($message->attachment_path)->toBeNull()->and($message->body)->toBe('Plain text');
    Http::assertSentCount(1);
});

test('an empty send with no file still fails validation', function () {
    ['admin' => $admin, 'lead' => $lead] = mediaLead();

    composer($admin, $lead)->call('sendMessage')->assertHasErrors(['newMessageBody' => 'required']);
});

// ---------------------------------------------------------------- display

test('the composer offers an Image and a PDF button with the channel\'s own accepted types', function () {
    ['admin' => $admin, 'lead' => $lead] = mediaLead();
    ['admin' => $messengerAdmin, 'lead' => $messengerLead] = mediaLead(LeadSourceType::FacebookMessenger);

    composer($admin, $lead)->assertSeeHtml('accept="image/jpeg,image/png"')->assertSeeHtml('accept="application/pdf"')->assertSee('Image')->assertSee('PDF');
    composer($messengerAdmin, $messengerLead)->assertSeeHtml('accept="image/jpeg,image/png,image/gif"');
});

test('a sent image and pdf appear in the chat feed as outgoing bubbles with a download link', function () {
    ['admin' => $admin, 'lead' => $lead, 'tenant' => $tenant, 'conversation' => $conversation] = mediaLead();
    $base = ['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'conversation_id' => $conversation->id, 'channel' => MessageChannel::Whatsapp];
    $image = Message::factory()->outbound()->withStatus(MessageStatus::Delivered)->create($base + [
        'message_type' => MessageType::Image, 'body' => 'Look at this', 'sent_by' => $admin->id,
        'attachment_path' => 'outbound/x/whatsapp/a.jpg', 'attachment_mime' => 'image/jpeg', 'attachment_name' => 'photo.jpg', 'attachment_size' => 2048,
    ]);
    $pdf = Message::factory()->outbound()->create($base + [
        'message_type' => MessageType::Document, 'body' => null, 'sent_by' => $admin->id,
        'attachment_path' => 'outbound/x/whatsapp/b.pdf', 'attachment_mime' => 'application/pdf', 'attachment_name' => 'quote.pdf', 'attachment_size' => 3 * 1024 * 1024,
    ]);

    $html = composer($admin, $lead)->html();

    $imageUrl = route('tenant-leads.message-attachment', [$lead, $image]);
    $pdfUrl = route('tenant-leads.message-attachment', [$lead, $pdf]);

    expect($html)->toContain($imageUrl)
        ->toContain($pdfUrl)
        ->toContain('Look at this')->toContain('quote.pdf')->toContain('PDF · 3 MB')->toContain('Sent by '.$admin->name);

    // Each attachment link must sit inside an outgoing (right-aligned) bubble
    // — structural alignment, not a Tailwind color utility, so this stays
    // meaningful regardless of how the bubble's own color/gradient is styled.
    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();
    $xpath = new DOMXPath($document);

    foreach ([$imageUrl, $pdfUrl] as $url) {
        $outgoingAncestors = $xpath->query('//a[@href="'.$url.'"]/ancestor::div[contains(concat(" ", normalize-space(@class), " "), " justify-end ")]');
        expect($outgoingAncestors->length)->toBeGreaterThan(0);
    }
});

// -------------------------------------------------------------- download

test('an authorized user can view an outbound file inline, and others cannot', function () {
    ['admin' => $admin, 'lead' => $lead, 'tenant' => $tenant, 'conversation' => $conversation] = mediaLead();
    $message = Message::factory()->outbound()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'conversation_id' => $conversation->id,
        'message_type' => MessageType::Document, 'body' => null,
        'attachment_path' => 'outbound/x/whatsapp/q.pdf', 'attachment_mime' => 'application/pdf', 'attachment_name' => 'quote.pdf', 'attachment_size' => 9,
    ]);
    Storage::disk('local')->put($message->attachment_path, '%PDF-1.4 x');

    $this->actingAs($admin)->get(route('tenant-leads.message-attachment', [$lead, $message]))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');

    $otherLead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $this->actingAs($admin)->get(route('tenant-leads.message-attachment', [$otherLead, $message]))->assertNotFound();

    ['admin' => $strangerAdmin] = mediaLead();
    auth()->logout();
    $this->actingAs($strangerAdmin)->get(route('tenant-leads.message-attachment', [$lead, $message]))->assertForbidden();

    auth()->logout();
    $this->get(route('tenant-leads.message-attachment', [$lead, $message]))->assertRedirect();
});

test('an inbound message is never served by the attachment route', function () {
    ['admin' => $admin, 'lead' => $lead, 'tenant' => $tenant, 'conversation' => $conversation] = mediaLead();
    $message = Message::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'conversation_id' => $conversation->id,
        'message_type' => MessageType::Image, 'attachment_path' => 'outbound/x/whatsapp/in.jpg', 'attachment_mime' => 'image/jpeg',
    ]);
    Storage::disk('local')->put($message->attachment_path, 'x');

    $this->actingAs($admin)->get(route('tenant-leads.message-attachment', [$lead, $message]))->assertNotFound();
});

// --------------------------------------------------------------- cleanup

test('deleting a lead removes its outbound files, and the orphan sweep only removes unreferenced outbound files', function () {
    ['lead' => $lead, 'tenant' => $tenant, 'conversation' => $conversation] = mediaLead();
    $kept = Message::factory()->outbound()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'conversation_id' => $conversation->id,
        'message_type' => MessageType::Image, 'attachment_path' => 'outbound/t/whatsapp/kept.jpg', 'attachment_mime' => 'image/jpeg', 'attachment_name' => 'kept.jpg',
    ]);
    $disk = Storage::disk('local');
    $disk->put('outbound/t/whatsapp/kept.jpg', 'k');
    $disk->put('outbound/t/whatsapp/orphan-old.jpg', 'o');
    $disk->put('outbound/t/whatsapp/orphan-new.jpg', 'n');
    touch($disk->path('outbound/t/whatsapp/kept.jpg'), now()->subDay()->timestamp);
    touch($disk->path('outbound/t/whatsapp/orphan-old.jpg'), now()->subDay()->timestamp);

    $this->artisan('leads:cleanup-attachments')->assertSuccessful();

    $disk->assertExists('outbound/t/whatsapp/kept.jpg');
    $disk->assertMissing('outbound/t/whatsapp/orphan-old.jpg');
    $disk->assertExists('outbound/t/whatsapp/orphan-new.jpg');

    $lead->delete();

    $disk->assertMissing('outbound/t/whatsapp/kept.jpg');
    expect(Message::find($kept->id))->toBeNull();
});
