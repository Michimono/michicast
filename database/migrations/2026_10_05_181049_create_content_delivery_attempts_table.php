<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Existing finished deliveries are backfilled as their first attempt, so a
     * failure recorded before attempts existed stays visible after a retry.
     */
    public function up(): void
    {
        Schema::create('content_delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broadcast_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');
            $table->string('failure')->nullable();
            $table->text('error_detail')->nullable();
            $table->string('external_reference')->nullable();
            $table->boolean('is_retry')->default(false);
            $table->timestamp('attempted_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['content_delivery_id', 'attempted_at']);
        });

        DB::table('content_delivery_attempts')->insertUsing(
            [
                'content_delivery_id',
                'broadcast_run_id',
                'status',
                'failure',
                'error_detail',
                'external_reference',
                'is_retry',
                'attempted_at',
                'finished_at',
                'created_at',
                'updated_at',
            ],
            fn (Builder $query) => $query
                ->from('content_deliveries')
                ->whereIn('status', ['delivered', 'failed'])
                ->select([
                    'id',
                    'broadcast_run_id',
                    'status',
                    'failure',
                    'error_detail',
                    'external_reference',
                    DB::raw('0'),
                    'attempted_at',
                    'finished_at',
                    'created_at',
                    'updated_at',
                ]),
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_delivery_attempts');
    }
};
