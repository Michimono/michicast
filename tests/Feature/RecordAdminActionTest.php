<?php

namespace Tests\Feature;

use App\Enums\AdminAction;
use App\Models\AdminActionLog;
use App\Models\User;
use App\Support\RecordAdminAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class RecordAdminActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_writes_a_row_with_actor_subject_and_reason(): void
    {
        $admin = User::factory()->create();
        $subject = User::factory()->create();

        $this->actingAs($admin);
        $this->instance('request', Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.55']));

        $entry = app(RecordAdminAction::class)(
            AdminAction::SignedIn,
            $subject,
            'investigating report',
            [
                'status' => ['before' => 'active', 'after' => 'banned'],
            ],
        );

        $this->assertInstanceOf(AdminActionLog::class, $entry);
        $this->assertTrue($entry->wasRecentlyCreated);

        $this->assertDatabaseHas('admin_actions', [
            'id' => $entry->id,
            'admin_id' => $admin->id,
            'action' => AdminAction::SignedIn->value,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->id,
            'reason' => 'investigating report',
            'ip_address' => '203.0.113.55',
        ]);

        $this->assertEquals(
            ['status' => ['before' => 'active', 'after' => 'banned']],
            $entry->fresh()->changes,
        );
    }

    public function test_failed_mutation_leaves_no_audit_row(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin);

        try {
            DB::transaction(function () use ($admin): void {
                app(RecordAdminAction::class)(
                    AdminAction::SignedIn,
                    $admin,
                    'should roll back',
                );

                throw new RuntimeException('deliberate failure');
            });
        } catch (RuntimeException) {
            // Expected — the transaction must roll back the audit insert.
        }

        $this->assertDatabaseCount('admin_actions', 0);
    }

    public function test_changes_never_include_secrets_or_forbidden_keys(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin);

        $entry = app(RecordAdminAction::class)(
            AdminAction::SignedIn,
            $admin,
            null,
            [
                'password' => 'super-secret-password',
                'credentials' => ['bot_token' => '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw'],
                'config' => ['feed_url' => 'https://example.test/feed'],
                'note' => 'Bearer sk-abcdefghijklmnopqrstuvwxyz012345',
                'email' => ['before' => 'a@example.test', 'after' => 'b@example.test'],
            ],
        );

        $changes = $entry->fresh()->changes;

        $this->assertIsArray($changes);
        $this->assertArrayNotHasKey('password', $changes);
        $this->assertArrayNotHasKey('credentials', $changes);
        $this->assertArrayNotHasKey('config', $changes);
        $this->assertEquals('a@example.test', $changes['email']['before']);
        $this->assertEquals('b@example.test', $changes['email']['after']);
        $this->assertSame('[redacted]', $changes['note']);
        $this->assertStringNotContainsString('sk-abcdefghijklmnopqrstuvwxyz012345', json_encode($changes));
        $this->assertStringNotContainsString('super-secret-password', json_encode($changes));
    }

    public function test_service_requires_an_authenticated_admin(): void
    {
        $subject = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(RecordAdminAction::class)(AdminAction::SignedIn, $subject);
    }
}
