<?php

use App\Livewire\Platform\LeadManagement\Settings;
use App\Models\Currency;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\LeadStatusLog;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

test('mother admin can create a custom lead status', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->set('statusName', 'On Hold')
        ->set('statusColor', 'purple')
        ->set('statusIsClosed', false)
        ->call('saveStatus')
        ->assertHasNoErrors();

    $status = LeadStatus::where('slug', 'on-hold')->firstOrFail();
    expect($status->name)->toBe('On Hold');
    expect($status->color)->toBe('purple');
    expect($status->is_closed)->toBeFalse();
});

test('mother admin can edit an existing lead status', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $status = LeadStatus::factory()->create(['name' => 'Draft', 'color' => 'zinc']);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('editStatus', $status->id)
        ->set('statusName', 'Archived')
        ->set('statusColor', 'red')
        ->set('statusIsClosed', true)
        ->call('saveStatus')
        ->assertHasNoErrors();

    $status->refresh();
    expect($status->name)->toBe('Archived');
    expect($status->color)->toBe('red');
    expect($status->is_closed)->toBeTrue();
});

test('two statuses cannot share the same name', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    LeadStatus::factory()->create(['name' => 'Escalated', 'slug' => 'escalated']);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->set('statusName', 'Escalated')
        ->call('saveStatus');

    expect(LeadStatus::where('name', 'Escalated')->count())->toBe(1);
});

test('a status referenced by a lead cannot be deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $status = $tenant->leadStatuses()->first();
    Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => $status->id]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('deleteStatus', $status->id)
        ->assertDispatched('toast-show');

    expect(LeadStatus::whereKey($status->id)->exists())->toBeTrue();
});

test('a status selected in a tenant pipeline but used by no leads cannot be deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $status = $tenant->leadStatuses()->first();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('deleteStatus', $status->id)
        ->assertDispatched('toast-show');

    expect(LeadStatus::whereKey($status->id)->exists())->toBeTrue();
});

test('a status used by no client can be deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $status = LeadStatus::factory()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('deleteStatus', $status->id)
        ->assertDispatched('toast-show');

    expect(LeadStatus::whereKey($status->id)->exists())->toBeFalse();
});

test('deleting a status goes through the confirm modal', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $status = LeadStatus::factory()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('confirmDeleteStatus', $status->id)
        ->assertSet('showDeleteStatusModal', true)
        ->assertSet('confirmingDeleteStatusId', $status->id)
        ->call('deleteStatusConfirmed')
        ->assertSet('showDeleteStatusModal', false);

    expect(LeadStatus::whereKey($status->id)->exists())->toBeFalse();
});

/**
 * Regression coverage for the LeadStatusLog dependent-check gap: a status
 * with zero *current* leads and not selected in any tenant's pipeline —
 * passing both pre-existing checks — can still be permanently referenced
 * by historical lead_status_logs.old_status_id/new_status_id rows
 * (restrictOnDelete()), from a lead that has since moved to a different
 * status. Before the fix, this reached $status->delete() and the
 * database's own FK constraint raised an uncaught QueryException; the
 * new check detects it first and produces the same graceful "in use"
 * toast every other dependent check already does.
 */
test('a status referenced only by historical status-change logs cannot be deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $statusA = LeadStatus::factory()->create();
    $statusB = LeadStatus::factory()->create();
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => $statusB->id]);

    // TenantFactory attaches the entire LeadStatus catalog to every new
    // tenant's pipeline (see its own docblock) — detach A explicitly so
    // this test isolates the LeadStatusLog check from the pre-existing
    // tenant_lead_statuses check.
    $tenant->leadStatuses()->detach($statusA->id);

    // The lead was moved into A, then out of A into B — the exact shape a
    // real transition produces, leaving A referenced by both rows even
    // though the lead's current status_id is B. changed_by is explicitly
    // null (nullable, not relevant here) rather than left to the
    // factory's default TenantUser::factory(), which would otherwise
    // create its own stray Tenant — auto-attaching the full LeadStatus
    // catalog (per TenantFactory) and reintroducing a tenant_lead_statuses
    // row for A that this test deliberately isolates against below.
    LeadStatusLog::factory()->create(['lead_id' => $lead->id, 'changed_by' => null, 'old_status_id' => null, 'new_status_id' => $statusA->id]);
    LeadStatusLog::factory()->create(['lead_id' => $lead->id, 'changed_by' => null, 'old_status_id' => $statusA->id, 'new_status_id' => $statusB->id]);

    // Both pre-existing checks would incorrectly pass: no current lead
    // sits in A, and A isn't selected in this tenant's pipeline.
    expect(Lead::where('status_id', $statusA->id)->exists())->toBeFalse();
    expect(DB::table('tenant_lead_statuses')->where('lead_status_id', $statusA->id)->exists())->toBeFalse();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('deleteStatus', $statusA->id)
        ->assertDispatched('toast-show');

    expect(LeadStatus::whereKey($statusA->id)->exists())->toBeTrue();
});

test('mother admin can create a custom currency', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->set('currencyCode', 'eur')
        ->set('currencyName', 'Euro')
        ->call('saveCurrency')
        ->assertHasNoErrors();

    $currency = Currency::where('code', 'EUR')->firstOrFail();
    expect($currency->name)->toBe('Euro');
});

test('mother admin can edit an existing currency', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $currency = Currency::factory()->create(['code' => 'GBP', 'name' => 'Pound']);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('editCurrency', $currency->id)
        ->set('currencyName', 'British Pound')
        ->call('saveCurrency')
        ->assertHasNoErrors();

    expect($currency->fresh()->name)->toBe('British Pound');
});

test('two currencies cannot share the same code', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    Currency::factory()->create(['code' => 'JPY']);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->set('currencyCode', 'jpy')
        ->set('currencyName', 'Japanese Yen (duplicate)')
        ->call('saveCurrency')
        ->assertHasErrors(['currencyCode']);

    expect(Currency::where('code', 'JPY')->count())->toBe(1);
});

test('a currency referenced by a lead cannot be deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $currency = $tenant->currencies()->first();
    Lead::factory()->create(['tenant_id' => $tenant->id, 'estimated_budget' => 100, 'estimated_budget_currency_id' => $currency->id]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('deleteCurrency', $currency->id)
        ->assertDispatched('toast-show');

    expect(Currency::whereKey($currency->id)->exists())->toBeTrue();
});

test('a currency enabled for a tenant but used by no leads cannot be deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $currency = $tenant->currencies()->first();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('deleteCurrency', $currency->id)
        ->assertDispatched('toast-show');

    expect(Currency::whereKey($currency->id)->exists())->toBeTrue();
});

test('a currency used by no client can be deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $currency = Currency::factory()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('deleteCurrency', $currency->id)
        ->assertDispatched('toast-show');

    expect(Currency::whereKey($currency->id)->exists())->toBeFalse();
});

test('deleting a currency goes through the confirm modal', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $currency = Currency::factory()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('confirmDeleteCurrency', $currency->id)
        ->assertSet('showDeleteCurrencyModal', true)
        ->assertSet('confirmingDeleteCurrencyId', $currency->id)
        ->call('deleteCurrencyConfirmed')
        ->assertSet('showDeleteCurrencyModal', false);

    expect(Currency::whereKey($currency->id)->exists())->toBeFalse();
});

/**
 * Concurrency-fix regression coverage for deleteStatus(), combining two
 * distinct proofs in one focused test:
 *
 * 1. Mirrors PlatformLeadSourceDeletionTest.php's/LeadSourceCostCatalogTest.php's
 *    own equivalent tests exactly: proves the LeadStatus row lookup used for
 *    the lock is compiled into the query the code actually runs, for a
 *    status with zero dependents. True concurrent-transaction blocking from
 *    lockForUpdate() can't be observed under SQLite (this app's test driver
 *    has no row-level locking to exercise, and a second genuinely separate
 *    connection to the same in-memory database isn't possible) — that
 *    guarantee comes from lockForUpdate() compiling to a real
 *    `SELECT ... FOR UPDATE` on Postgres (production), standard RDBMS
 *    behavior this suite can't execute end-to-end.
 *
 * 2. A real (non-queued) Eloquent `deleted` event listener — fired inside
 *    the transaction, strictly after the DELETE statement has already
 *    executed — deliberately throws, on a second, independent status. This
 *    genuinely distinguishes fixed from unfixed behavior: without
 *    DB::transaction(), the DELETE is already committed by the time this
 *    listener runs, so the status row would stay gone despite the
 *    exception; with it, the delete rolls back and the status survives.
 */
test('deleting a lead status locks the LeadStatus row and rolls back if the delete fails after it runs', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $status = LeadStatus::factory()->create();

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('deleteStatus', $status->id);

    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $lockedStatusLookup = $queries->first(fn (string $sql): bool => str_contains($sql, 'from "lead_statuses"') && str_contains($sql, '"lead_statuses"."id" = ?'));

    expect($lockedStatusLookup)->not->toBeNull();
    expect(LeadStatus::whereKey($status->id)->exists())->toBeFalse();

    $secondStatus = LeadStatus::factory()->create();

    Event::listen('eloquent.deleted: '.LeadStatus::class, function (): void {
        throw new RuntimeException('Forcing a failure after deleteStatus()\'s delete has already run.');
    });

    expect(fn () => Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('deleteStatus', $secondStatus->id))
        ->toThrow(RuntimeException::class);

    Event::forget('eloquent.deleted: '.LeadStatus::class);

    expect(LeadStatus::whereKey($secondStatus->id)->exists())->toBeTrue();
});

/**
 * Concurrency-fix regression coverage for deleteCurrency() — mirrors the
 * deleteStatus() test above exactly; see its own docblock for the full
 * reasoning on the SQLite locking limitation and the rollback technique.
 */
test('deleting a currency locks the Currency row and rolls back if the delete fails after it runs', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $currency = Currency::factory()->create();

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('deleteCurrency', $currency->id);

    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $lockedCurrencyLookup = $queries->first(fn (string $sql): bool => str_contains($sql, 'from "currencies"') && str_contains($sql, '"currencies"."id" = ?'));

    expect($lockedCurrencyLookup)->not->toBeNull();
    expect(Currency::whereKey($currency->id)->exists())->toBeFalse();

    $secondCurrency = Currency::factory()->create();

    Event::listen('eloquent.deleted: '.Currency::class, function (): void {
        throw new RuntimeException('Forcing a failure after deleteCurrency()\'s delete has already run.');
    });

    expect(fn () => Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('deleteCurrency', $secondCurrency->id))
        ->toThrow(RuntimeException::class);

    Event::forget('eloquent.deleted: '.Currency::class);

    expect(Currency::whereKey($secondCurrency->id)->exists())->toBeTrue();
});
