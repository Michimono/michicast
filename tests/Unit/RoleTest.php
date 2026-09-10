<?php

namespace Tests\Unit;

use App\Enums\Role;
use PHPUnit\Framework\TestCase;

class RoleTest extends TestCase
{
    public function test_a_role_carries_at_least_its_own_authority(): void
    {
        foreach (Role::cases() as $role) {
            $this->assertTrue($role->atLeast($role));
        }
    }

    public function test_the_order_runs_viewer_editor_admin_owner(): void
    {
        $this->assertTrue(Role::Owner->atLeast(Role::Admin));
        $this->assertTrue(Role::Admin->atLeast(Role::Editor));
        $this->assertTrue(Role::Editor->atLeast(Role::Viewer));

        $this->assertFalse(Role::Admin->atLeast(Role::Owner));
        $this->assertFalse(Role::Editor->atLeast(Role::Admin));
        $this->assertFalse(Role::Viewer->atLeast(Role::Editor));
    }

    public function test_an_owner_outranks_every_role(): void
    {
        foreach (Role::cases() as $role) {
            $this->assertTrue(Role::Owner->atLeast($role));
        }
    }

    public function test_a_viewer_outranks_nobody_else(): void
    {
        $this->assertFalse(Role::Viewer->atLeast(Role::Editor));
        $this->assertFalse(Role::Viewer->atLeast(Role::Admin));
        $this->assertFalse(Role::Viewer->atLeast(Role::Owner));
    }

    public function test_every_role_is_offered_with_a_readable_label(): void
    {
        $this->assertSame([
            'owner' => 'Owner',
            'admin' => 'Admin',
            'editor' => 'Editor',
            'viewer' => 'Viewer',
        ], Role::options());
    }
}
