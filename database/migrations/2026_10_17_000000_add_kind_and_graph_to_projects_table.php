<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A project is either a simple broadcast (one source, one destination,
     * managed from the overview) or a visual broadcast whose routes are
     * compiled from a node graph. The graph is the editor's document; the
     * compiled routes still live in broadcasts, so execution is unchanged.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('kind', 16)->default('simple')->after('description');
            $table->json('graph')->nullable()->after('kind');

            $table->index(['organization_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'kind']);
            $table->dropColumn(['kind', 'graph']);
        });
    }
};
