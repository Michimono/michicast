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
        Schema::table('content_deliveries', function (Blueprint $table) {
            $table->timestamp('retry_requested_at')->nullable()->after('retry_after_seconds');
            $table->index(['broadcast_id', 'retry_requested_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('content_deliveries', function (Blueprint $table) {
            $table->dropIndex(['broadcast_id', 'retry_requested_at']);
            $table->dropColumn('retry_requested_at');
        });
    }
};
