<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * MySQL may satisfy the project_id foreign key with the composite
     * (project_id, enabled) index from the create migration, so that index
     * cannot be dropped until the FK is removed (or a dedicated project_id
     * index exists). Drop FK → drop composite + column → restore FK.
     */
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'enabled']);
            $table->dropColumn('enabled');
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::table('destinations', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        Schema::table('destinations', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'enabled']);
            $table->dropColumn('enabled');
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->boolean('enabled')->default(true)->after('type');
            $table->index(['project_id', 'enabled']);
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::table('destinations', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        Schema::table('destinations', function (Blueprint $table) {
            $table->boolean('enabled')->default(true)->after('type');
            $table->index(['project_id', 'enabled']);
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }
};
