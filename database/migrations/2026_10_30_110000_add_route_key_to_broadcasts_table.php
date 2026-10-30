<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each canvas path from a source node to a destination node owns its own
     * broadcasts row. Paths leaving the same source node for the same
     * destination node share a route group: an item is sent once per group,
     * by the first path (in route order) that lets it through. Existing rows
     * keep null keys and are claimed by their path on the next save.
     */
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->string('route_key', 64)->nullable()->after('destination_id');
            $table->string('route_group', 64)->nullable()->after('route_key');
            $table->unsignedInteger('route_order')->default(0)->after('route_group');
        });
    }

    public function down(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->dropColumn(['route_key', 'route_group', 'route_order']);
        });
    }
};
