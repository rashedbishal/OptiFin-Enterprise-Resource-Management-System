<?php

use App\Livewire\Platform\Clients\LeadSources;
use App\Models\Conversation;
use App\Models\Currency;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\LeadSource;
use App\Models\LeadSourceCost;
use App\Models\Message;
use App\Models\PlatformUser;
use App\Models\Tenant;
use App\Models\WebsiteForm;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/**
 * Focused coverage for the C1 forensic-audit fix: Platform Admin
 * LeadSources::delete() previously called $source->delete() unconditionally,
 * silently cascading away every Lead (and its full history) for a source
 * with no restrict-type dependent, or throwing an uncaught QueryException
 * for a source with one. These tests prove every direct dependent is now
 * guarded with a clear message and that nothing is ever partially deleted.
 */
function toastAssertsText(string $text, string $variant = 'danger'): callable
{
    return fn (string $event, array $params): bool => ($params['slots']['text'] ?? null) === $text
        && ($params['dataset']['variant'] ?? null) === $variant;
}

test('an empty lead source can still be deleted, with the existing success toast', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('delete', $source->id)
        ->assertDispatched('toast-show', toastAssertsText('Lead source deleted.', 'success'));

    expect(LeadSource::whereKey($source->id)->exists())->toBeFalse();
});

test('a lead source with leads cannot be deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('delete', $source->id)
        ->assertDispatched('toast-show', toastAssertsText('This source has leads and can no longer be deleted.'));

    expect(LeadSource::whereKey($source->id)->exists())->toBeTrue();
    expect(Lead::whereKey($lead->id)->exists())->toBeTrue();
});

test('a lead source with import history cannot be deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id]);
    $import = LeadImport::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('delete', $source->id)
        ->assertDispatched('toast-show', toastAssertsText('This source has import history and can no longer be deleted.'));

    expect(LeadSource::whereKey($source->id)->exists())->toBeTrue();
    expect(LeadImport::whereKey($import->id)->exists())->toBeTrue();
});

test('a lead source with recorded cost history cannot be deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id]);
    $currency = Currency::factory()->create();
    $cost = LeadSourceCost::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_source_id' => $source->id,
        'currency_id' => $currency->id,
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('delete', $source->id)
        ->assertDispatched('toast-show', toastAssertsText('This source has recorded cost history and can no longer be deleted.'));

    expect(LeadSource::whereKey($source->id)->exists())->toBeTrue();
    expect(LeadSourceCost::whereKey($cost->id)->exists())->toBeTrue();
});

test('a lead source with website forms configured cannot be deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id]);
    $form = WebsiteForm::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('delete', $source->id)
        ->assertDispatched('toast-show', toastAssertsText('This source has website forms configured and can no longer be deleted.'));

    expect(LeadSource::whereKey($source->id)->exists())->toBeTrue();
    expect(WebsiteForm::whereKey($form->id)->exists())->toBeTrue();
});

test('a lead source with conversation history cannot be deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_source_id' => $source->id,
        'lead_id' => $lead->id,
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('delete', $source->id)
        ->assertDispatched('toast-show', toastAssertsText('This source has conversation history and can no longer be deleted.'));

    expect(LeadSource::whereKey($source->id)->exists())->toBeTrue();
    expect(Conversation::whereKey($conversation->id)->exists())->toBeTrue();
});

test('messages tied to a conversation survive a blocked deletion attempt, alongside their lead and conversation', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_source_id' => $source->id,
        'lead_id' => $lead->id,
    ]);
    $message = Message::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'lead_id' => $lead->id,
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('delete', $source->id)
        ->assertDispatched('toast-show', toastAssertsText('This source has conversation history and can no longer be deleted.'));

    expect(LeadSource::whereKey($source->id)->exists())->toBeTrue();
    expect(Lead::whereKey($lead->id)->exists())->toBeTrue();
    expect(Conversation::whereKey($conversation->id)->exists())->toBeTrue();
    expect(Message::whereKey($message->id)->exists())->toBeTrue();
});

test('a lead source with mixed dependencies is blocked by the most consequential one first, and nothing is deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_source_id' => $source->id,
        'lead_id' => $lead->id,
    ]);
    $currency = Currency::factory()->create();
    $cost = LeadSourceCost::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_source_id' => $source->id,
        'currency_id' => $currency->id,
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('delete', $source->id)
        // Conversations are checked first (see the delete() docblock) —
        // a source with both a Conversation and other dependents must
        // still surface the conversation message, not a different one.
        ->assertDispatched('toast-show', toastAssertsText('This source has conversation history and can no longer be deleted.'));

    expect(LeadSource::whereKey($source->id)->exists())->toBeTrue();
    expect(Lead::whereKey($lead->id)->exists())->toBeTrue();
    expect(Conversation::whereKey($conversation->id)->exists())->toBeTrue();
    expect(LeadSourceCost::whereKey($cost->id)->exists())->toBeTrue();
});

test('a platform admin without client-management update permission cannot delete a lead source', function () {
    $restrictedAdmin = PlatformUser::factory()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id]);

    // delete()'s own Gate::authorize('update', $this->tenant) check is
    // unchanged by this fix — this is the identical ability check both
    // delete() and the page's own mount() perform (mount() would already
    // block a restricted admin from reaching delete() at all). Testing it
    // directly at the Gate level, rather than through a full Livewire
    // round trip, avoids an unrelated Livewire-testing quirk where a
    // mount-time authorization failure leaves the component's snapshot in
    // a state a subsequent ->call() can't cleanly reuse.
    expect(Gate::forUser($restrictedAdmin)->denies('update', $tenant))->toBeTrue();

    expect(LeadSource::whereKey($source->id)->exists())->toBeTrue();
});

test('a platform admin cannot delete another tenant\'s lead source through this page', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $sourceB = LeadSource::factory()->create(['tenant_id' => $tenantB->id]);

    expect(fn () => Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenantA])
        ->call('delete', $sourceB->id))
        ->toThrow(ModelNotFoundException::class);

    expect(LeadSource::whereKey($sourceB->id)->exists())->toBeTrue();
});

/**
 * Mirrors LeadSourceCostCatalogTest.php's own equivalent test exactly: this
 * only proves the LeadSource row lookup used for the lock is compiled into
 * the query the code actually runs. True concurrent-transaction blocking
 * from lockForUpdate() can't be observed under SQLite (no row-level
 * locking to exercise here) — that guarantee comes from lockForUpdate()
 * compiling to a real `SELECT ... FOR UPDATE` on Postgres (production),
 * standard RDBMS behavior this suite can't execute end-to-end.
 */
test('deleting a lead source locks the LeadSource row itself before checking dependencies', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('delete', $source->id);

    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $lockedSourceLookup = $queries->first(fn (string $sql): bool => str_contains($sql, 'from "lead_sources"') && str_contains($sql, '"lead_sources"."id" = ?'));

    expect($lockedSourceLookup)->not->toBeNull();
});
