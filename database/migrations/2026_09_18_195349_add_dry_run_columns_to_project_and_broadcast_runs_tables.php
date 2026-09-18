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
        Schema::table('project_runs', function (Blueprint $table) {
            $table->boolean('is_dry')->default(false)->after('status');
        });

        Schema::table('broadcast_runs', function (Blueprint $table) {
            $table->json('dry_report')->nullable()->after('error_detail');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_runs', function (Blueprint $table) {
            $table->dropColumn('is_dry');
        });

        Schema::table('broadcast_runs', function (Blueprint $table) {
            $table->dropColumn('dry_report');
        });
    }
};
