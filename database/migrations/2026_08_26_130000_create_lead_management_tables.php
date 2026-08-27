<?php

use App\Enums\AutoDistributionMethod;
use App\Enums\LeadDistributionMode;
use App\Enums\LeadPriority;
use App\Enums\LeadSourceType;
use App\Enums\LeadStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->enum('lead_distribution_mode', array_column(LeadDistributionMode::cases(), 'value'))
                ->default(LeadDistributionMode::Manual->value);
            $table->enum('auto_distribution_method', array_column(AutoDistributionMethod::cases(), 'value'))
                ->nullable();
        });

        Schema::table('tenant_users', function (Blueprint $table) {
            $table->timestamp('last_assigned_at')->nullable();
        });

        Schema::create('lead_sources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->enum('type', array_column(LeadSourceType::cases(), 'value'));
            $table->string('name');
            $table->text('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('source_id')->constrained('lead_sources')->cascadeOnDelete();
            $table->foreignUlid('assigned_to')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('message')->nullable();
            $table->json('source_data')->nullable();
            $table->enum('status', array_column(LeadStatus::cases(), 'value'))->default(LeadStatus::New->value);
            $table->enum('priority', array_column(LeadPriority::cases(), 'value'))->default(LeadPriority::Medium->value);
            $table->text('notes')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('first_contacted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('lead_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignUlid('assigned_to')->constrained('tenant_users')->cascadeOnDelete();
            $table->foreignUlid('assigned_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->text('assignment_note')->nullable();
            $table->timestamps();
        });

        Schema::create('lead_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignUlid('changed_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->enum('old_status', array_column(LeadStatus::cases(), 'value'))->nullable();
            $table->enum('new_status', array_column(LeadStatus::cases(), 'value'));
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('lead_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignUlid('tenant_user_id')->constrained('tenant_users')->cascadeOnDelete();
            $table->text('note');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_notes');
        Schema::dropIfExists('lead_status_logs');
        Schema::dropIfExists('lead_assignments');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('lead_sources');

        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropColumn('last_assigned_at');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['lead_distribution_mode', 'auto_distribution_method']);
        });
    }
};
