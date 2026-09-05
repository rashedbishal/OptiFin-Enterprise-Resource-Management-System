<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Brings lead_assignments, lead_notes, lead_status_logs, and
 * lead_detail_update_logs in line with every other table in the Lead
 * Management module (leads, lead_sources, lead_statuses, lead_attachments,
 * currencies), which all use ULID primary keys — these four were the only
 * ones still on plain auto-increment bigints. Purely a primary-key type
 * change: none of these IDs are ever exposed in the UI, an API response, or
 * a URL, and nothing else in the schema has a foreign key pointing *at*
 * any of them, so this only ever touches each table's own `id` column.
 *
 * Rebuilds each table under a new name rather than altering `id` in place
 * (add ulid column, drop the old bigint one, rename) — that simpler
 * approach works fine on Postgres but SQLite's schema diffing refuses to
 * ever drop a column that was an INTEGER PRIMARY KEY (its rowid alias),
 * even after the primary key constraint itself is dropped and the column
 * renamed out of the way first. A full create+copy+swap sidesteps that
 * SQLite limitation entirely, since CREATE/DROP/RENAME TABLE have no such
 * restriction on any driver this app supports.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rebuild('lead_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignUlid('assigned_to')->constrained('tenant_users')->cascadeOnDelete();
            $table->foreignUlid('assigned_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->text('assignment_note')->nullable();
            $table->timestamps();
        });

        $this->rebuild('lead_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignUlid('tenant_user_id')->constrained('tenant_users')->cascadeOnDelete();
            $table->text('note');
            $table->timestamps();
        });

        $this->rebuild('lead_status_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignUlid('changed_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->foreignUlid('old_status_id')->nullable()->constrained('lead_statuses')->restrictOnDelete();
            $table->foreignUlid('new_status_id')->constrained('lead_statuses')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        $this->rebuild('lead_detail_update_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignUlid('changed_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->json('changed_fields');
            $table->string('action')->nullable();
            $table->date('action_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Creates `{table}_new` per $defineNewTable, copies every row across
     * (replacing the old bigint id with a freshly generated ulid, keeping
     * every other column as-is), then drops the old table and renames the
     * new one into its place.
     */
    private function rebuild(string $table, Closure $defineNewTable): void
    {
        $newTable = "{$table}_new";

        Schema::create($newTable, $defineNewTable);

        DB::table($table)->orderBy('id')->chunkById(500, function ($rows) use ($newTable): void {
            $inserts = [];

            foreach ($rows as $row) {
                $data = (array) $row;
                unset($data['id']);
                $data['id'] = (string) Str::ulid();
                $inserts[] = $data;
            }

            DB::table($newTable)->insert($inserts);
        });

        Schema::drop($table);
        Schema::rename($newTable, $table);
    }

    /**
     * Not a faithful reverse: the original bigint values are gone (nothing
     * else in the schema ever referenced them, so nothing preserved them
     * either) — this renumbers rows in ULID order instead, which is at
     * least chronological, the closest meaningful ordering left to rebuild
     * sequential IDs from.
     */
    public function down(): void
    {
        $this->rebuildBack('lead_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignUlid('assigned_to')->constrained('tenant_users')->cascadeOnDelete();
            $table->foreignUlid('assigned_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->text('assignment_note')->nullable();
            $table->timestamps();
        });

        $this->rebuildBack('lead_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignUlid('tenant_user_id')->constrained('tenant_users')->cascadeOnDelete();
            $table->text('note');
            $table->timestamps();
        });

        $this->rebuildBack('lead_status_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignUlid('changed_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->foreignUlid('old_status_id')->nullable()->constrained('lead_statuses')->restrictOnDelete();
            $table->foreignUlid('new_status_id')->constrained('lead_statuses')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        $this->rebuildBack('lead_detail_update_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignUlid('changed_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->json('changed_fields');
            $table->string('action')->nullable();
            $table->date('action_date')->nullable();
            $table->timestamps();
        });
    }

    private function rebuildBack(string $table, Closure $defineNewTable): void
    {
        $newTable = "{$table}_new";

        Schema::create($newTable, $defineNewTable);

        $number = 1;

        DB::table($table)->orderBy('id')->chunkById(500, function ($rows) use ($newTable, &$number): void {
            $inserts = [];

            foreach ($rows as $row) {
                $data = (array) $row;
                unset($data['id']);
                $data['id'] = $number++;
                $inserts[] = $data;
            }

            DB::table($newTable)->insert($inserts);
        });

        Schema::drop($table);
        Schema::rename($newTable, $table);
    }
};
