<?php

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
        Schema::table('leads', function (Blueprint $table) {
            $table->string('website')->nullable()->after('company');
            $table->string('industry')->nullable()->after('website');
            $table->string('location')->nullable()->after('industry');
            $table->string('service_interest')->nullable()->after('location');
            $table->decimal('estimated_budget', 12, 2)->nullable()->after('service_interest');
            $table->decimal('deal_value', 12, 2)->nullable()->after('estimated_budget');
            $table->unsignedTinyInteger('win_probability')->nullable()->after('deal_value');
            $table->date('next_follow_up_at')->nullable()->after('win_probability');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'website', 'industry', 'location', 'service_interest',
                'estimated_budget', 'deal_value', 'win_probability', 'next_follow_up_at',
            ]);
        });
    }
};
