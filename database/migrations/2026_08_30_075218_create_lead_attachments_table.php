<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lead_attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->string('type');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->text('original_media_url')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['lead_id', 'sent_at']);
        });

        // Carry over any lead that already has a single attachment under the
        // old columns — best-effort, since this table only ever held "the
        // most recent" attachment; sent_at falls back to the lead's own
        // updated_at, the closest available approximation of when it landed.
        DB::table('leads')
            ->whereNotNull('attachment_path')
            ->get(['id', 'attachment_path', 'attachment_type', 'attachment_mime', 'original_media_url', 'updated_at'])
            ->each(function (object $lead): void {
                DB::table('lead_attachments')->insert([
                    'id' => (string) Str::ulid(),
                    'lead_id' => $lead->id,
                    'type' => $lead->attachment_type,
                    'path' => $lead->attachment_path,
                    'mime' => $lead->attachment_mime,
                    'original_media_url' => $lead->original_media_url,
                    'sent_at' => $lead->updated_at,
                    'created_at' => $lead->updated_at,
                    'updated_at' => $lead->updated_at,
                ]);
            });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_type', 'attachment_mime', 'original_media_url']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->after('html_body');
            $table->string('attachment_type')->nullable()->after('attachment_path');
            $table->string('attachment_mime')->nullable()->after('attachment_type');
            $table->text('original_media_url')->nullable()->after('attachment_mime');
        });

        DB::table('lead_attachments')
            ->orderBy('sent_at')
            ->get()
            ->groupBy('lead_id')
            ->each(function ($attachments, string $leadId): void {
                $latest = $attachments->last();

                DB::table('leads')->where('id', $leadId)->update([
                    'attachment_path' => $latest->path,
                    'attachment_type' => $latest->type,
                    'attachment_mime' => $latest->mime,
                    'original_media_url' => $latest->original_media_url,
                ]);
            });

        Schema::dropIfExists('lead_attachments');
    }
};
