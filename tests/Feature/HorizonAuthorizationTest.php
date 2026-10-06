<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class HorizonAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_horizon_denies_a_normal_user(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(Gate::forUser($user)->denies('viewHorizon'));
    }
}
