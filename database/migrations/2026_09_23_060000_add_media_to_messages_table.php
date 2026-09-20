<?php

use App\Enums\MessageType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Outbound image/PDF replies: the file itself lives on the private
     * `local` disk (never under `leads/`, which leads:cleanup-attachments
     * sweeps), these columns just point at it. `message_type` also gains
     * `image`/`document` — same Postgres-CHECK-vs-SQLite-table-rewrite
     * split as 2026_09_12_080002_add_csv_import_to_lead_sources_type_check.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('attachment_path')->nullable();
            $table->string('attachment_mime')->nullable();
            $table->string('attachment_name')->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
        });

        $this->setTypeConstraint(MessageType::cases());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('messages')->whereIn('message_type', [MessageType::Image->value, MessageType::Document->value])->delete();

        $this->setTypeConstraint(array_filter(
            MessageType::cases(),
            fn (MessageType $type): bool => ! in_array($type, [MessageType::Image, MessageType::Document], true),
        ));

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_mime', 'attachment_name', 'attachment_size']);
        });
    }

    /**
     * @param  array<int, MessageType>  $cases
     */
    private function setTypeConstraint(array $cases): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $values = implode(',', array_map(fn (MessageType $type): string => "'{$type->value}'", $cases));

            DB::statement('ALTER TABLE messages DROP CONSTRAINT messages_message_type_check');
            DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_message_type_check CHECK (message_type IN ({$values}))");

            return;
        }

        Schema::table('messages', function (Blueprint $table) use ($cases) {
            $table->enum('message_type', array_column($cases, 'value'))->default(MessageType::Text->value)->change();
        });
    }
};
