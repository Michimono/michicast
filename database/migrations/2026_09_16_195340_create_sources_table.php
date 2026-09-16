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
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->boolean('enabled')->default(true);
            // Provider settings only. A column named after a provider belongs here, not in the schema.
            $table->json('config')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_successful')->nullable();
            $table->string('last_test_message')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
