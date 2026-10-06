<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('organization_quota_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('metric');
            $table->unsignedInteger('limit')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'metric']);
        });

        $defaultPlan = (string) config('michicast.quotas.default_plan', 'free');

        DB::table('organizations')->whereNull('plan')->update(['plan' => $defaultPlan]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_quota_overrides');
    }
};
