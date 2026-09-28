<?php

namespace Tests\Support;

use App\Actions\Tenant\RecordInboundCall;
use App\Enums\CallMetaStage;
use App\Enums\CallStatus;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\LeadSource;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\LeadCallSdp;
use ArrayObject;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Throwable;

/**
 * Shared builder for the WhatsApp inbound-calling Step 4 tests: one tenant with
 * a Client Admin (NOT routed), two routed agents, a WhatsApp source with calling
 * on and an access token, a lead, and one Ringing call whose encrypted offer is
 * cached the way Step 2 caches it. Plus the small helpers the race tests need: a
 * Meta fake that records requests, log capture, and a query-triggered
 * interleaving hook.
 */
final class LeadCallScenario
{
    public const string TOKEN = 'EAAG-call-sentinel-token-5e2a';

    public const string CALL_ID = 'wacid.STEP4-CALL-1';

    public const string ANSWER_SENTINEL = 'SENTINEL-ANSWER-3b7d';

    public function __construct(
        public readonly Tenant $tenant,
        public readonly TenantUser $admin,
        public readonly TenantUser $agent,
        public readonly TenantUser $other,
        public readonly LeadSource $source,
        public readonly Lead $lead,
        public LeadCall $call,
    ) {}

    /**
     * @param  array<string, mixed>  $call
     * @param  array<string, mixed>  $source
     */
    public static function make(array $call = [], array $source = [], bool $offer = true): self
    {
        ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();

        $agent = clientUserWithLeadPermission($tenant, ['view', 'update']);
        $other = clientUserWithLeadPermission($tenant, ['view', 'update']);
        $leadSource = WhatsAppCallFixtures::source($tenant, $source, ['access_token' => self::TOKEN]);
        $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $leadSource->id]);

        $leadCall = LeadCall::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'lead_source_id' => $leadSource->id,
            'external_call_id' => self::CALL_ID,
            'routed_user_ids' => [$agent->id, $other->id],
            'assignee_id_at_arrival' => null,
            'ringing_expires_at' => now()->addSeconds(60),
        ], $call));

        $scenario = new self($tenant, $admin, $agent, $other, $leadSource, $lead, $leadCall);

        if ($offer) {
            Cache::put(RecordInboundCall::offerCacheKey($leadSource, $leadCall->external_call_id), Crypt::encryptString(WhatsAppCallFixtures::sdp()), now()->addMinutes(2));
        }

        return $scenario;
    }

    public static function answerSdp(): string
    {
        return "v=0\r\no=- 99 2 IN IP4 127.0.0.1\r\ns=".self::ANSWER_SENTINEL."\r\nt=0 0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\na=setup:active\r\n";
    }

    /**
     * The call as the agent's claim leaves it.
     */
    public function claimed(?TenantUser $by = null): self
    {
        $this->call->update([
            'status' => CallStatus::Answering,
            'claimed_by' => ($by ?? $this->agent)->id,
            'claimed_at' => now(),
        ]);

        return $this->reload();
    }

    /**
     * The call as `answer` leaves it: claimed, pre_accepted, answer cached.
     */
    public function preAccepted(?TenantUser $by = null): self
    {
        $this->claimed($by);
        $this->call->update(['meta_stage' => CallMetaStage::PreAccepted]);
        LeadCallSdp::storeAnswer($this->source, $this->call->external_call_id, self::answerSdp());

        return $this->reload();
    }

    /**
     * The call as `accept` leaves it: claimed, Connected, both SDP caches
     * cleared (accept() drops them on a successful connect). `$connectedAt`
     * lets a test simulate an old-enough Connected call for the Step 6 sweep.
     */
    public function connected(?TenantUser $by = null, ?CarbonInterface $connectedAt = null): self
    {
        $this->preAccepted($by);
        $this->call->update([
            'status' => CallStatus::Connected,
            'connected_at' => $connectedAt ?? now(),
            'meta_stage' => CallMetaStage::Accepted,
        ]);
        LeadCallSdp::forget($this->source, $this->call->external_call_id);

        return $this->reload();
    }

    public function reload(): self
    {
        $this->call = $this->call->fresh();

        return $this;
    }

    public function fresh(): LeadCall
    {
        return $this->call->fresh();
    }

    public function offerCached(): bool
    {
        return Cache::has(RecordInboundCall::offerCacheKey($this->source, $this->call->external_call_id));
    }

    public function answerCached(): bool
    {
        return Cache::has(LeadCallSdp::answerKey($this->source, $this->call->external_call_id));
    }

    /**
     * Fakes Meta for every request and records each one. `$step` is a response,
     * a Throwable (thrown), or a Closure run at request time (receiving the
     * request) that returns one of those.
     *
     * @return ArrayObject<int, array{method: string, url: string, body: array<string, mixed>, authorization: string}>
     */
    public static function fakeMeta(mixed $step = null): ArrayObject
    {
        Http::swap(new Factory);

        $log = new ArrayObject;

        Http::fake(function (Request $request) use ($log, $step) {
            $log[] = [
                'method' => $request->method(),
                'url' => $request->url(),
                'body' => $request->data(),
                'authorization' => $request->header('Authorization')[0] ?? '',
            ];

            $result = $step instanceof Closure ? $step($request) : $step;
            $result ??= Http::response(['messaging_product' => 'whatsapp', 'success' => true]);

            if ($result instanceof Throwable) {
                throw $result;
            }

            return $result;
        });

        return $log;
    }

    /**
     * Runs `$action` once, right after the first query whose SQL contains
     * `$needle` — a deterministic way to make another request "win" between two
     * steps of the code under test.
     */
    public static function interleaveAfterQuery(string $needle, Closure $action): void
    {
        $fired = false;

        DB::listen(function ($query) use (&$fired, $needle, $action): void {
            if ($fired || ! str_contains($query->sql, $needle)) {
                return;
            }

            $fired = true;
            $action();
        });
    }

    public static function captureLogs(): TestHandler
    {
        config([
            'logging.default' => 'lcs-capture',
            'logging.channels.lcs-capture' => ['driver' => 'monolog', 'handler' => TestHandler::class],
            'logging.channels.whatsapp' => ['driver' => 'stack', 'channels' => ['lcs-capture']],
        ]);

        $handler = Log::channel('lcs-capture')->getLogger()->getHandlers()[0];

        expect($handler)->toBeInstanceOf(TestHandler::class);

        return $handler;
    }

    public static function logText(TestHandler $handler): string
    {
        return (string) json_encode(array_map(
            fn ($record): array => [$record->message, $record->context],
            $handler->getRecords(),
        ));
    }
}
