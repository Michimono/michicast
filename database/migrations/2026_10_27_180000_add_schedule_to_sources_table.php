<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Visual broadcasts run each source on its own schedule. A source with no
     * schedule follows its broadcast's, so existing rows need no backfill.
     */
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->json('schedule')->nullable()->after('config');
            $table->timestamp('next_run_at')->nullable()->after('schedule');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['schedule', 'next_run_at']);
        });
    }
};
