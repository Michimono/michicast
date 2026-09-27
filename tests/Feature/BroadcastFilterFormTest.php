<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastFilterFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_editor_can_save_filter_rules(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $broadcast = $this->broadcastIn($organization);

        $this->actingAs($user)
            ->get(route('broadcast-filters.edit', [$organization, $broadcast]))
            ->assertOk()
            ->assertSee('Filters');

        $this->actingAs($user)
            ->patch(route('broadcast-filters.update', [$organization, $broadcast]), [
                'rules' => [
                    [
                        'type' => 'contains',
                        'field' => 'title',
                        'value' => 'Pokemon',
                    ],
                    [
                        'type' => 'published_after',
                        'hours' => 24,
                    ],
                ],
            ])
            ->assertRedirect(route('broadcast-filters.edit', [$organization, $broadcast]))
            ->assertSessionHas('success', 'The filters have been saved.');

        $this->assertSame([
            'rules' => [
                ['type' => 'contains', 'field' => 'title', 'value' => 'Pokemon'],
                ['type' => 'published_after', 'hours' => 24],
            ],
        ], $broadcast->refresh()->filter);
    }

    public function test_an_invalid_regular_expression_is_rejected_when_saved(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $broadcast = $this->broadcastIn($organization);

        $this->actingAs($user)
            ->patch(route('broadcast-filters.update', [$organization, $broadcast]), [
                'rules' => [
                    [
                        'type' => 'title_matches',
                        'value' => '(',
                    ],
                ],
            ])
            ->assertSessionHasErrors('rules.0.value');

        $this->assertNull($broadcast->refresh()->filter);
    }

    public function test_clearing_all_rules_stores_a_null_filter(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $broadcast = $this->broadcastIn($organization, [
            'filter' => [
                'rules' => [
                    ['type' => 'author_equals', 'value' => 'Official'],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->patch(route('broadcast-filters.update', [$organization, $broadcast]), [
                'rules' => [],
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($broadcast->refresh()->filter);
    }

    public function test_a_viewer_cannot_edit_filters(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $broadcast = $this->broadcastIn($organization);

        $this->actingAs($user)
            ->get(route('broadcast-filters.edit', [$organization, $broadcast]))
            ->assertForbidden();

        $this->actingAs($user)
            ->patch(route('broadcast-filters.update', [$organization, $broadcast]), [
                'rules' => [
                    ['type' => 'author_equals', 'value' => 'Official'],
                ],
            ])
            ->assertForbidden();
    }

    public function test_the_broadcast_edit_screen_links_to_filters(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $broadcast = $this->broadcastIn($organization);

        $this->actingAs($user)
            ->get(route('flows.edit', [$organization, $broadcast]))
            ->assertOk()
            ->assertSee(route('broadcast-filters.edit', [$organization, $broadcast]), false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function broadcastIn(Organization $organization, array $attributes = []): Broadcast
    {
        $project = Project::factory()->for($organization)->create();

        return Broadcast::factory()->for($project)->create($attributes);
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function memberHolding(Role $role): array
    {
        $user = User::factory()->create();

        return [$user, Organization::factory()->withMember($user, $role)->create()];
    }
}
