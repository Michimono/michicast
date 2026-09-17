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
        Schema::create('broadcast_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broadcast_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('items_found')->default(0);
            $table->unsignedInteger('items_new')->default(0);
            $table->unsignedInteger('items_skipped')->default(0);
            $table->unsignedInteger('items_delivered')->default(0);
            $table->unsignedInteger('items_failed')->default(0);
            $table->text('error_detail')->nullable();
            $table->timestamps();

            $table->index(['broadcast_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('broadcast_runs');
    }
};
