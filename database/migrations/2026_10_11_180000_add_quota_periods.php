<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Existing usage rows were all daily counters, so the new period column
     * defaults to daily and behaviour is unchanged until an admin picks
     * another period.
     */
    public function up(): void
    {
        Schema::table('organization_usage', function (Blueprint $table) {
            $table->string('period')->default('daily')->after('metric');
        });

        Schema::table('organization_usage', function (Blueprint $table) {
            $table->unique(['organization_id', 'metric', 'period', 'period_start'], 'organization_usage_period_unique');
            $table->dropUnique(['organization_id', 'metric', 'period_start']);
        });

        Schema::create('organization_quota_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('metric');
            $table->string('period');
            $table->timestamps();

            $table->unique(['organization_id', 'metric']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_quota_periods');

        Schema::table('organization_usage', function (Blueprint $table) {
            $table->unique(['organization_id', 'metric', 'period_start']);
            $table->dropUnique('organization_usage_period_unique');
        });

        Schema::table('organization_usage', function (Blueprint $table) {
            $table->dropColumn('period');
        });
    }
};
