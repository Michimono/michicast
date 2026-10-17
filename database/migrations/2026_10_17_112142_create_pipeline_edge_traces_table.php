<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a dry run carried across each connection of a visual broadcast: a
 * bounded sample of the items that entered the edge and whether each one
 * went on to the next node. One row per edge and dry run; older runs are
 * pruned when a new trace set is stored, so storage stays bounded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_edge_traces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_run_id')->constrained()->cascadeOnDelete();
            $table->string('edge_id', 64);
            $table->string('from_node_id', 64);
            $table->string('to_node_id', 64);
            $table->string('status', 24);
            $table->unsignedInteger('items_in')->default(0);
            $table->unsignedInteger('items_out')->default(0);
            $table->string('summary', 500)->nullable();
            $table->json('items')->nullable();
            $table->timestamps();

            $table->unique(['project_run_id', 'edge_id']);
            $table->index(['project_id', 'edge_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_edge_traces');
    }
};
