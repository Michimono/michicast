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
        Schema::create('content_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broadcast_id')->constrained()->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broadcast_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');
            $table->string('external_reference')->nullable();
            $table->string('failure')->nullable();
            $table->text('error_detail')->nullable();
            $table->unsignedInteger('retry_after_seconds')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['content_item_id', 'broadcast_id', 'destination_id'], 'content_deliveries_route_unique');
            $table->index(['broadcast_id', 'status']);
            $table->index(['destination_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_deliveries');
    }
};
