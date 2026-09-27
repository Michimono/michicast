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
        Schema::table('sources', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'enabled']);
            $table->dropColumn('enabled');
        });

        Schema::table('destinations', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'enabled']);
            $table->dropColumn('enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->boolean('enabled')->default(true)->after('type');
            $table->index(['project_id', 'enabled']);
        });

        Schema::table('destinations', function (Blueprint $table) {
            $table->boolean('enabled')->default(true)->after('type');
            $table->index(['project_id', 'enabled']);
        });
    }
};
