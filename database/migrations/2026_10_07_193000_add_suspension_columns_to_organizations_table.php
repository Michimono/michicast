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
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('plan');
            $table->text('suspension_reason')->nullable()->after('suspended_at');
            $table->foreignId('suspended_by')->nullable()->after('suspension_reason')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('suspended_by');
            $table->dropColumn(['suspended_at', 'suspension_reason']);
        });
    }
};
