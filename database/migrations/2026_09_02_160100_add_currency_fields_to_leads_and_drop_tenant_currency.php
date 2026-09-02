<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignUlid('estimated_budget_currency_id')->nullable()->after('estimated_budget')->constrained('currencies')->restrictOnDelete();
            $table->foreignUlid('deal_value_currency_id')->nullable()->after('deal_value')->constrained('currencies')->restrictOnDelete();
        });

        $this->backfillLeadCurrencies();

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }

    /**
     * Every existing lead's budget/deal value was implicitly in its
     * tenant's single `currency` — now that each tenant has that same
     * currency attached via tenant_currencies (previous migration), point
     * every lead with a non-null amount at it directly so nothing displays
     * as "no currency" the moment this ships. A lead with no amount set
     * doesn't need a currency either, so this only ever touches rows
     * where estimated_budget/deal_value is already non-null.
     */
    private function backfillLeadCurrencies(): void
    {
        $tenantCurrencyIds = DB::table('tenant_currencies')
            ->select('tenant_id', 'currency_id')
            ->orderBy('id')
            ->get()
            ->groupBy('tenant_id')
            ->map(fn ($rows) => $rows->first()->currency_id);

        DB::table('leads')->select('id', 'tenant_id', 'estimated_budget', 'deal_value')->orderBy('id')
            ->chunkById(500, function ($leads) use ($tenantCurrencyIds) {
                foreach ($leads as $lead) {
                    $currencyId = $tenantCurrencyIds[$lead->tenant_id] ?? null;

                    if ($currencyId === null) {
                        continue;
                    }

                    $update = [];

                    if ($lead->estimated_budget !== null) {
                        $update['estimated_budget_currency_id'] = $currencyId;
                    }

                    if ($lead->deal_value !== null) {
                        $update['deal_value_currency_id'] = $currencyId;
                    }

                    if ($update !== []) {
                        DB::table('leads')->where('id', $lead->id)->update($update);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('currency')->default('USD')->after('country');
        });

        DB::table('tenants')->select('tenants.id', 'tenant_currencies.currency_id', 'currencies.code')
            ->join('tenant_currencies', 'tenant_currencies.tenant_id', '=', 'tenants.id')
            ->join('currencies', 'currencies.id', '=', 'tenant_currencies.currency_id')
            ->orderBy('tenants.id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('tenants')->where('id', $row->id)->update(['currency' => $row->code]);
                }
            }, 'tenants.id', 'id');

        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('estimated_budget_currency_id');
            $table->dropConstrainedForeignId('deal_value_currency_id');
        });
    }
};
