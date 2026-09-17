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
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedInteger('consecutive_failures')->default(0)->after('claimed_at');
            $table->timestamp('last_success_at')->nullable()->after('consecutive_failures');
            $table->timestamp('last_failure_at')->nullable()->after('last_success_at');
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->unsignedInteger('consecutive_failures')->default(0)->after('last_test_message');
        });

        Schema::table('destinations', function (Blueprint $table) {
            $table->unsignedInteger('consecutive_failures')->default(0)->after('last_test_message');
        });

        Schema::table('broadcasts', function (Blueprint $table) {
            $table->unsignedInteger('consecutive_failures')->default(0)->after('enabled');
            $table->text('failure_pause_reason')->nullable()->after('consecutive_failures');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['consecutive_failures', 'last_success_at', 'last_failure_at']);
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('consecutive_failures');
        });

        Schema::table('destinations', function (Blueprint $table) {
            $table->dropColumn('consecutive_failures');
        });

        Schema::table('broadcasts', function (Blueprint $table) {
            $table->dropColumn(['consecutive_failures', 'failure_pause_reason']);
        });
    }
};
