<?php

use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Move each organization's owner into a membership row.
     */
    public function up(): void
    {
        $owners = DB::table('organizations')->select('id', 'owner_id')->get();

        foreach ($owners as $organization) {
            DB::table('organization_members')->insertOrIgnore([
                'organization_id' => $organization->id,
                'user_id' => $organization->owner_id,
                'role' => Role::Owner->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('id')->constrained('users');
        });

        $owners = DB::table('organization_members')
            ->where('role', Role::Owner->value)
            ->orderBy('id')
            ->get();

        foreach ($owners as $member) {
            DB::table('organizations')
                ->where('id', $member->organization_id)
                ->update(['owner_id' => $member->user_id]);
        }
    }
};
