<?php

use App\Enums\ModuleScope;
use App\Enums\MonitoringTool;
use App\Livewire\Platform\Monitoring\Health;
use App\Livewire\Platform\Monitoring\Horizon;
use App\Livewire\Platform\Monitoring\Pulse;
use App\Models\Module;
use App\Models\Permission;
use App\Models\PlatformUser;
use App\Models\Role;
use App\Models\RoleModulePermission;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\Jobs\RetryFailedJob;
use Laravel\Pulse\Facades\Pulse as PulseFacade;
use Livewire\Livewire;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\Health\Facades\Health as HealthFacade;
use Spatie\Health\ResultStores\CacheHealthResultStore;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResults;

/**
 * A Platform Admin whose role grants the given permissions on each given
 * monitoring tool's platform module.
 *
 * @param  array<string, list<string>>  $grants  tool slug => permission slugs
 */
function platformAdminWithMonitoring(array $grants): PlatformUser
{
    (new PermissionSeeder)->run();

    $role = Role::create(['name' => 'Monitor '.Str::random(6), 'slug' => 'monitor-'.Str::random(6)]);

    foreach ($grants as $slug => $permissions) {
        $module = Module::where('slug', $slug)->firstOrFail();
        $role->modules()->attach($module->id);

        RoleModulePermission::query()->insert(
            Permission::whereIn('slug', $permissions)->pluck('id')
                ->map(fn (string $permissionId): array => ['role_id' => $role->id, 'module_id' => $module->id, 'permission_id' => $permissionId])
                ->all()
        );
    }

    return PlatformUser::factory()->create(['role_id' => $role->id]);
}

/**
 * Horizon repositories backed by fixed data, so no test touches Redis.
 */
function fakeHorizonRepositories(): void
{
    $failedJob = (object) [
        'id' => 'failed-job-1',
        'name' => 'App\\Jobs\\SendOutboundMessage',
        'queue' => 'default',
        'failed_at' => (string) now()->subMinutes(3)->timestamp,
        'exception' => "RuntimeException: Meta rejected the message\n#0 /app/Jobs/SendOutboundMessage.php(42)",
    ];

    app()->instance(MasterSupervisorRepository::class, Mockery::mock(MasterSupervisorRepository::class, ['all' => [(object) ['status' => 'running']]]));
    app()->instance(SupervisorRepository::class, Mockery::mock(SupervisorRepository::class, ['all' => [(object) ['name' => 'host:supervisor-1', 'status' => 'running', 'processes' => ['redis:default' => 3]]]]));
    app()->instance(MetricsRepository::class, Mockery::mock(MetricsRepository::class, ['jobsProcessedPerMinute' => 12]));
    app()->instance(WorkloadRepository::class, Mockery::mock(WorkloadRepository::class, ['get' => [['name' => 'default', 'length' => 4, 'wait' => 2, 'processes' => 3, 'split_queues' => null]]]));
    app()->instance(JobRepository::class, Mockery::mock(JobRepository::class, [
        'countRecent' => 250,
        'countRecentlyFailed' => 1,
        'getFailed' => collect([$failedJob]),
        'findFailed' => $failedJob,
    ]));
}

// ── Modules ────────────────────────────────────────────────────────────

test('the three monitoring tools exist as platform system modules', function () {
    $modules = Module::whereIn('slug', ['horizon', 'health', 'pulse'])->get();

    expect($modules)->toHaveCount(3);
    expect($modules->every(fn (Module $module): bool => $module->scope === ModuleScope::Platform && $module->is_system))->toBeTrue();
});

// ── Access ─────────────────────────────────────────────────────────────

test('a guest is sent to the admin login', function (string $route) {
    $this->get(route($route))->assertRedirect(route('admin.login'));
})->with(['admin.monitoring.horizon', 'admin.monitoring.health', 'admin.monitoring.pulse']);

test('an admin without the tool\'s module is refused, and one with view access gets in', function (MonitoringTool $tool) {
    fakeHorizonRepositories();
    $without = platformAdminWithMonitoring([]);
    $with = platformAdminWithMonitoring([$tool->value => ['view']]);

    $this->actingAs($without, 'platform')->get(route($tool->routeName()))->assertForbidden();
    $this->actingAs($with, 'platform')->get(route($tool->routeName()))->assertOk();
})->with(MonitoringTool::cases());

test('access to one tool never grants another', function () {
    $healthOnly = platformAdminWithMonitoring(['health' => ['view']]);

    $this->actingAs($healthOnly, 'platform')->get(route('admin.monitoring.pulse'))->assertForbidden();
    $this->actingAs($healthOnly, 'platform')->get(route('admin.monitoring.horizon'))->assertForbidden();
});

test('the Mother Admin can open every monitoring page', function (MonitoringTool $tool) {
    fakeHorizonRepositories();

    $this->actingAs(PlatformUser::factory()->motherAdmin()->create(), 'platform')
        ->get(route($tool->routeName()))
        ->assertOk();
})->with(MonitoringTool::cases());

test('the native Horizon and Pulse dashboards follow the same module permission', function (string $path, string $slug) {
    $without = platformAdminWithMonitoring([]);
    $with = platformAdminWithMonitoring([$slug => ['view']]);

    $this->actingAs($without, 'platform')->get($path)->assertForbidden();
    $this->actingAs($with, 'platform')->get($path)->assertOk();
})->with([
    'horizon' => ['/admin/horizon', 'horizon'],
    'pulse' => ['/admin/pulse', 'pulse'],
]);

test('the sidebar lists only the monitoring tools the admin may open', function () {
    $admin = platformAdminWithMonitoring(['health' => ['view']]);

    $this->actingAs($admin, 'platform')->get(route('admin.monitoring.health'))
        ->assertSee(route('admin.monitoring.health'))
        ->assertDontSee(route('admin.monitoring.horizon'))
        ->assertDontSee(route('admin.monitoring.pulse'));
});

// ── Horizon ────────────────────────────────────────────────────────────

test('the Horizon page shows status, workload and failed jobs', function () {
    fakeHorizonRepositories();
    $admin = platformAdminWithMonitoring(['horizon' => ['view']]);

    Livewire::actingAs($admin, 'platform')->test(Horizon::class)
        ->assertSee('Running')
        ->assertSee('250')
        ->assertSee('SendOutboundMessage')
        ->assertSee('RuntimeException: Meta rejected the message')
        ->assertDontSeeHtml('wire:click="retry(');
});

test('the Horizon page explains a Redis outage instead of failing', function () {
    app()->instance(MasterSupervisorRepository::class, Mockery::mock(MasterSupervisorRepository::class, fn ($mock) => $mock->shouldReceive('all')->andThrow(new RuntimeException('Connection refused'))));
    $admin = platformAdminWithMonitoring(['horizon' => ['view']]);

    Livewire::actingAs($admin, 'platform')->test(Horizon::class)
        ->assertOk()
        ->assertSee('Connection refused');
});

test('retrying a failed job needs update permission', function () {
    fakeHorizonRepositories();
    Bus::fake([RetryFailedJob::class]);
    $viewer = platformAdminWithMonitoring(['horizon' => ['view']]);

    Livewire::actingAs($viewer, 'platform')->test(Horizon::class)
        ->call('retry', 'failed-job-1')
        ->assertForbidden();

    Bus::assertNotDispatched(RetryFailedJob::class);
});

test('an admin with update permission can retry a failed job', function () {
    fakeHorizonRepositories();
    Bus::fake([RetryFailedJob::class]);
    $operator = platformAdminWithMonitoring(['horizon' => ['view', 'update']]);

    Livewire::actingAs($operator, 'platform')->test(Horizon::class)
        ->assertSeeHtml('wire:click="retry(')
        ->call('retry', 'failed-job-1');

    Bus::assertDispatched(RetryFailedJob::class, fn (RetryFailedJob $job): bool => $job->id === 'failed-job-1');
});

// ── Health ─────────────────────────────────────────────────────────────

test('the Health page lists the latest check results, failing checks first', function () {
    cache()->store(config('health.result_stores.'.CacheHealthResultStore::class.'.store'))->put('health:storeResults', (new StoredCheckResults(now(), collect([
        new StoredCheckResult(name: 'Database', label: 'Database', shortSummary: 'Ok', status: 'ok'),
        new StoredCheckResult(name: 'Redis', label: 'Redis', notificationMessage: 'Connection refused', shortSummary: 'Failed', status: 'failed'),
    ])))->toJson());
    $admin = platformAdminWithMonitoring(['health' => ['view']]);

    Livewire::actingAs($admin, 'platform')->test(Health::class)
        ->assertSeeInOrder(['Redis', 'Connection refused', 'Database'])
        ->assertSee('1 check is failing')
        ->assertDontSee('Run checks now');
});

test('running the checks on demand needs update permission and stores fresh results', function () {
    HealthFacade::clearChecks()->checks([
        new class extends Check
        {
            public function run(): Result
            {
                return Result::make()->ok('All good');
            }
        },
    ]);
    $viewer = platformAdminWithMonitoring(['health' => ['view']]);
    $operator = platformAdminWithMonitoring(['health' => ['view', 'update']]);

    Livewire::actingAs($viewer, 'platform')->test(Health::class)->call('runChecks')->assertForbidden();
    expect(app(ResultStore::class)->latestResults())->toBeNull();

    Livewire::actingAs($operator, 'platform')->test(Health::class)->call('runChecks')->assertSee('All good');
    expect(app(ResultStore::class)->latestResults()?->storedCheckResults)->toHaveCount(1);
});

// ── Pulse ──────────────────────────────────────────────────────────────

test('the Pulse page summarises recorded slow requests and exceptions', function () {
    PulseFacade::startRecording(); // PULSE_ENABLED=false in phpunit.xml stops recording
    PulseFacade::record('slow_request', json_encode(['GET', '/lead-management/leads', 'Index']), 2400)->max()->count();
    PulseFacade::record('exception', json_encode(['App\\Exceptions\\LeadMergeException', 'app/Services/LeadMergeService.php:240']), now()->timestamp)->max()->count();
    PulseFacade::ingest();
    $admin = platformAdminWithMonitoring(['pulse' => ['view']]);

    Livewire::actingAs($admin, 'platform')->test(Pulse::class)
        ->assertSee('/lead-management/leads')
        ->assertSee('2,400 ms')
        ->assertSee('LeadMergeException');
});

test('the Pulse page ignores an unknown period', function () {
    $admin = platformAdminWithMonitoring(['pulse' => ['view']]);

    Livewire::actingAs($admin, 'platform')->test(Pulse::class)
        ->set('period', 'forever')
        ->assertSet('period', '1_hour');
});
