<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $organization = Organization::factory()->ownedBy($user)->create([
            'name' => 'Test Organization',
            'slug' => 'test-organization',
        ]);

        Project::factory()->for($organization)->create([
            'name' => 'Pokemon TCGP News',
            'slug' => 'pokemon-tcgp-news',
            'description' => 'News from the Pokemon TCG Pocket world.',
        ]);
    }
}
