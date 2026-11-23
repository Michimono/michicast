<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->unsignedInteger('rate_limit_count')->nullable()->after('credentials');
            $table->unsignedInteger('rate_limit_minutes')->nullable()->after('rate_limit_count');
            $table->unsignedInteger('max_queue_size')->nullable()->after('rate_limit_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->dropColumn(['rate_limit_count', 'rate_limit_minutes', 'max_queue_size']);
        });
    }
};
