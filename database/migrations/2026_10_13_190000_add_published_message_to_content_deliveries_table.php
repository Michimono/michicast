<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The message an Activity Log destination kept instead of sending it (#202).
     */
    public function up(): void
    {
        Schema::table('content_deliveries', function (Blueprint $table) {
            $table->text('published_message')->nullable()->after('external_reference');
            $table->json('published_payload')->nullable()->after('published_message');
        });
    }

    public function down(): void
    {
        Schema::table('content_deliveries', function (Blueprint $table) {
            $table->dropColumn(['published_message', 'published_payload']);
        });
    }
};
