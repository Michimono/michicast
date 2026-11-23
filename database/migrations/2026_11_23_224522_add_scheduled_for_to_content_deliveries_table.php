<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_deliveries', function (Blueprint $table) {
            $table->timestamp('scheduled_for')->nullable()->after('attempted_at');
            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::table('content_deliveries', function (Blueprint $table) {
            $table->dropIndex(['status', 'scheduled_for']);
            $table->dropColumn('scheduled_for');
        });
    }
};
