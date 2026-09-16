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
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // Source model lands in #24; keep the column without a FK until then.
            $table->unsignedBigInteger('source_id');
            $table->string('external_id')->nullable();
            $table->string('identity_key', 191);
            $table->string('canonical_url', 2048)->nullable();
            $table->string('title')->nullable();
            $table->longText('body')->nullable();
            $table->string('author')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('discovered_at');
            $table->json('media')->nullable();
            $table->json('metadata')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('content_hash', 64);
            $table->string('lifecycle');
            $table->timestamps();

            $table->unique(['source_id', 'identity_key']);
            $table->index(['project_id', 'lifecycle']);
            $table->index(['source_id', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_items');
    }
};
