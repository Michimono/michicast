<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastTransformFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_editor_can_save_a_template_and_rules(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $broadcast = $this->broadcastIn($organization);

        $this->actingAs($user)
            ->get(route('broadcast-transforms.edit', [$organization, $broadcast]))
            ->assertOk()
            ->assertSee('Transform')
            ->assertSee('{{title}}', false);

        $this->actingAs($user)
            ->patch(route('broadcast-transforms.update', [$organization, $broadcast]), [
                'template' => "{{title}}\n\n{{url}}",
                'rules' => [
                    ['type' => 'strip_html'],
                    ['type' => 'truncate', 'length' => 280],
                ],
            ])
            ->assertRedirect(route('broadcast-transforms.edit', [$organization, $broadcast]))
            ->assertSessionHas('success', 'The transform has been saved.');

        $this->assertEquals([
            'template' => "{{title}}\n\n{{url}}",
            'rules' => [
                ['type' => 'strip_html'],
                ['type' => 'truncate', 'length' => 280],
            ],
        ], $broadcast->refresh()->transform);
    }

    public function test_an_unknown_placeholder_is_rejected_when_saved(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $broadcast = $this->broadcastIn($organization);

        $this->actingAs($user)
            ->patch(route('broadcast-transforms.update', [$organization, $broadcast]), [
                'template' => '{{title}} {{not_a_real_placeholder}}',
            ])
            ->assertSessionHasErrors('template');

        $this->assertNull($broadcast->refresh()->transform);
    }

    public function test_an_invalid_replace_pattern_is_rejected_when_saved(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $broadcast = $this->broadcastIn($organization);

        $this->actingAs($user)
            ->patch(route('broadcast-transforms.update', [$organization, $broadcast]), [
                'template' => '{{title}}',
                'rules' => [
                    ['type' => 'replace', 'pattern' => '(', 'replacement' => 'x'],
                ],
            ])
            ->assertSessionHasErrors('rules.0.pattern');
    }

    public function test_preview_renders_sample_output_without_saving(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $broadcast = $this->broadcastIn($organization);

        $this->actingAs($user)
            ->patch(route('broadcast-transform-previews.store', [$organization, $broadcast]), [
                'template' => 'Preview {{title}}',
                'rules' => [
                    ['type' => 'prepend', 'value' => 'Go:'],
                ],
            ])
            ->assertOk()
            ->assertSee('Preview')
            ->assertSee('Go:Preview Sample Pocket news');

        $this->assertNull($broadcast->refresh()->transform);
    }

    public function test_clearing_the_template_and_rules_stores_null(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $broadcast = $this->broadcastIn($organization, [
            'transform' => [
                'template' => '{{title}}',
                'rules' => [['type' => 'strip_html']],
            ],
        ]);

        $this->actingAs($user)
            ->patch(route('broadcast-transforms.update', [$organization, $broadcast]), [
                'template' => '',
                'rules' => [],
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($broadcast->refresh()->transform);
    }

    public function test_a_viewer_cannot_edit_transforms(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $broadcast = $this->broadcastIn($organization);

        $this->actingAs($user)
            ->get(route('broadcast-transforms.edit', [$organization, $broadcast]))
            ->assertForbidden();

        $this->actingAs($user)
            ->patch(route('broadcast-transforms.update', [$organization, $broadcast]), [
                'template' => '{{title}}',
            ])
            ->assertForbidden();
    }

    public function test_the_broadcast_edit_screen_links_to_transforms(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $broadcast = $this->broadcastIn($organization);

        $this->actingAs($user)
            ->get(route('broadcasts.edit', [$organization, $broadcast]))
            ->assertOk()
            ->assertSee(route('broadcast-transforms.edit', [$organization, $broadcast]), false);
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
