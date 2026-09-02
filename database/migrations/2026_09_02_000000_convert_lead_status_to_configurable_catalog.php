<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The 8 fixed statuses every tenant had before this migration, seeded
     * into the new catalog so nothing changes visually at launch. `slug`
     * doubles as the legacy `leads.status`/`lead_status_logs.*_status` enum
     * value it replaces, so the backfill below can map directly by string
     * equality instead of a separate translation table.
     *
     * @var array<int, array{slug: string, name: string, color: string, is_closed: bool}>
     */
    private array $legacyStatuses = [
        ['slug' => 'new', 'name' => 'New', 'color' => 'blue', 'is_closed' => false],
        ['slug' => 'contacted', 'name' => 'Contacted', 'color' => 'sky', 'is_closed' => false],
        ['slug' => 'qualified', 'name' => 'Qualified', 'color' => 'indigo', 'is_closed' => false],
        ['slug' => 'proposal_sent', 'name' => 'Proposal Sent', 'color' => 'amber', 'is_closed' => false],
        ['slug' => 'negotiation', 'name' => 'Negotiation', 'color' => 'orange', 'is_closed' => false],
        ['slug' => 'won', 'name' => 'Won', 'color' => 'green', 'is_closed' => true],
        ['slug' => 'lost', 'name' => 'Lost', 'color' => 'red', 'is_closed' => true],
        ['slug' => 'unqualified', 'name' => 'Unqualified', 'color' => 'zinc', 'is_closed' => true],
    ];

    public function up(): void
    {
        Schema::create('lead_statuses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('color');
            $table->boolean('is_closed')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('tenant_lead_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('lead_status_id')->constrained('lead_statuses')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['tenant_id', 'lead_status_id']);
            $table->unique(['tenant_id', 'position']);
        });

        $statusIds = $this->seedCatalog();

        $this->seedTenantPipelines($statusIds);

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignUlid('status_id')->nullable()->after('status')->constrained('lead_statuses')->restrictOnDelete();
        });

        Schema::table('lead_status_logs', function (Blueprint $table) {
            $table->foreignUlid('old_status_id')->nullable()->after('old_status')->constrained('lead_statuses')->restrictOnDelete();
            $table->foreignUlid('new_status_id')->nullable()->after('new_status')->constrained('lead_statuses')->restrictOnDelete();
        });

        $this->backfillColumn('leads', 'status', 'status_id', $statusIds);
        $this->backfillColumn('lead_status_logs', 'old_status', 'old_status_id', $statusIds);
        $this->backfillColumn('lead_status_logs', 'new_status', 'new_status_id', $statusIds);

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropColumn('status');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignUlid('status_id')->nullable(false)->change();
            $table->index(['tenant_id', 'status_id']);
        });

        Schema::table('lead_status_logs', function (Blueprint $table) {
            $table->dropColumn(['old_status', 'new_status']);
        });

        Schema::table('lead_status_logs', function (Blueprint $table) {
            $table->foreignUlid('new_status_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        $slugs = array_column($this->legacyStatuses, 'slug');

        Schema::table('lead_status_logs', function (Blueprint $table) use ($slugs) {
            $table->enum('old_status', $slugs)->nullable();
            $table->enum('new_status', $slugs)->nullable();
        });

        $slugById = DB::table('lead_statuses')->pluck('slug', 'id');

        DB::table('lead_status_logs')->select('id', 'old_status_id', 'new_status_id')->orderBy('id')
            ->chunkById(500, function ($logs) use ($slugById) {
                foreach ($logs as $log) {
                    DB::table('lead_status_logs')->where('id', $log->id)->update([
                        'old_status' => $log->old_status_id ? ($slugById[$log->old_status_id] ?? null) : null,
                        'new_status' => $slugById[$log->new_status_id] ?? 'new',
                    ]);
                }
            });

        Schema::table('lead_status_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('old_status_id');
            $table->dropConstrainedForeignId('new_status_id');
        });

        Schema::table('lead_status_logs', function (Blueprint $table) use ($slugs) {
            $table->enum('new_status', $slugs)->nullable(false)->change();
        });

        Schema::table('leads', function (Blueprint $table) use ($slugs) {
            $table->enum('status', $slugs)->nullable()->default('new');
        });

        DB::table('leads')->select('id', 'status_id')->orderBy('id')
            ->chunkById(500, function ($leads) use ($slugById) {
                foreach ($leads as $lead) {
                    DB::table('leads')->where('id', $lead->id)->update([
                        'status' => $slugById[$lead->status_id] ?? 'new',
                    ]);
                }
            });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_id');
        });

        Schema::table('leads', function (Blueprint $table) use ($slugs) {
            $table->enum('status', $slugs)->nullable(false)->default('new')->change();
            $table->index(['tenant_id', 'status']);
        });

        Schema::dropIfExists('tenant_lead_statuses');
        Schema::dropIfExists('lead_statuses');
    }

    /**
     * @return array<string, string> slug => catalog id
     */
    private function seedCatalog(): array
    {
        $ids = [];
        $now = now();

        foreach ($this->legacyStatuses as $index => $status) {
            $id = (string) Str::ulid();

            DB::table('lead_statuses')->insert([
                'id' => $id,
                'name' => $status['name'],
                'slug' => $status['slug'],
                'color' => $status['color'],
                'is_closed' => $status['is_closed'],
                'sort_order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $ids[$status['slug']] = $id;
        }

        return $ids;
    }

    /**
     * @param  array<string, string>  $statusIds
     */
    private function seedTenantPipelines(array $statusIds): void
    {
        $now = now();

        DB::table('tenants')->select('id')->orderBy('id')->chunkById(100, function ($tenants) use ($statusIds, $now) {
            $rows = [];

            foreach ($tenants as $tenant) {
                $position = 1;

                foreach ($statusIds as $statusId) {
                    $rows[] = [
                        'tenant_id' => $tenant->id,
                        'lead_status_id' => $statusId,
                        'position' => $position++,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if ($rows !== []) {
                DB::table('tenant_lead_statuses')->insert($rows);
            }
        });
    }

    /**
     * @param  array<string, string>  $statusIds
     */
    private function backfillColumn(string $table, string $enumColumn, string $idColumn, array $statusIds): void
    {
        DB::table($table)->select('id', $enumColumn)->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, $enumColumn, $idColumn, $statusIds) {
                foreach ($rows as $row) {
                    $value = $row->{$enumColumn};

                    if ($value !== null && isset($statusIds[$value])) {
                        DB::table($table)->where('id', $row->id)->update([$idColumn => $statusIds[$value]]);
                    }
                }
            });
    }
};
