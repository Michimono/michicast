<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Broadcast creation layout and schedule picker (#200).
 */
class BroadcastCreatePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_create_page_is_a_single_column_with_schedule_tiles(): void
    {
        [$user, $organization] = $this->editor();

        $this->actingAs($user)
            ->get(route('broadcasts.create', $organization))
            ->assertOk()
            ->assertSee('Back to broadcasts')
            ->assertSee('Create a broadcast')
            ->assertSee('Set up a schedule to collect content')
            ->assertSee('aria-label="Broadcast details and schedule"', false)
            ->assertSee('Schedules use this timezone, regardless of who views them.')
            // v2 is one column: no feature list beside the form.
            ->assertDontSee('Automated collection')
            ->assertSee('placeholder="e.g. Daily Pokémon News"', false)
            ->assertSee('Choose how often this broadcast should run.')
            ->assertSee('type="radio"', false)
            ->assertSee('name="frequency"', false)
            ->assertSeeInOrder(['value="interval"', 'value="daily"', 'value="weekly"'], false)
            ->assertSee('Every few hours')
            ->assertSee('Run every')
            ->assertSee('Run at')
            ->assertSee('>Mon<', false)
            ->assertSee('You can configure sources, mapping and destinations after creation.')
            ->assertSee('href="'.route('broadcasts.index', $organization).'"', false)
            ->assertDontSee('Monthly')
            ->assertDontSee('<select id="frequency"', false);
    }

    public function test_daily_is_checked_by_default_and_panels_are_not_server_hidden(): void
    {
        [$user, $organization] = $this->editor();

        $html = $this->actingAs($user)
            ->get(route('broadcasts.create', $organization))
            ->getContent();

        $this->assertIsString($html);
        $this->assertMatchesRegularExpression('/value="daily"\s+data-schedule-frequency\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="interval"\s+data-schedule-frequency\s+checked/', $html);
        // The reveal is CSS (:has), so panels must not carry a server-side hidden attribute.
        $this->assertDoesNotMatchRegularExpression('/data-schedule-panel="[^"]*"\s+hidden/', $html);
    }

    public function test_a_failed_submit_keeps_the_chosen_tile_and_values(): void
    {
        [$user, $organization] = $this->editor();

        $this->actingAs($user)
            ->from(route('broadcasts.create', $organization))
            ->post(route('broadcasts.store', $organization), [
                'name' => '',
                'frequency' => 'weekly',
                'time' => '18:30',
                'weekdays' => [2, 5],
                'timezone' => '',
            ])
            ->assertRedirect(route('broadcasts.create', $organization))
            ->assertSessionHasErrors('name');

        $html = $this->actingAs($user)
            ->get(route('broadcasts.create', $organization))
            ->getContent();

        $this->assertIsString($html);
        $this->assertMatchesRegularExpression('/value="weekly"\s+data-schedule-frequency\s+checked/', $html);
        $this->assertMatchesRegularExpression('/name="weekdays\[\]"\s+value="2"\s+checked/', $html);
        $this->assertMatchesRegularExpression('/name="weekdays\[\]"\s+value="5"\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="weekdays\[\]"\s+value="1"\s+checked/', $html);
        $this->assertStringContainsString('value="18:30"', $html);
    }

    public function test_a_missing_weekday_error_is_shown_inside_the_weekly_panel(): void
    {
        [$user, $organization] = $this->editor();

        $this->actingAs($user)
            ->from(route('broadcasts.create', $organization))
            ->post(route('broadcasts.store', $organization), [
                'name' => 'Weekly Show',
                'frequency' => 'weekly',
                'time' => '09:00',
            ]);

        $this->actingAs($user)
            ->get(route('broadcasts.create', $organization))
            ->assertSee('id="weekdays-error"', false)
            ->assertSee('Choose at least one weekday.')
            ->assertSee('aria-describedby="weekdays-error"', false);
    }

    public function test_each_tile_stores_the_same_schedule_as_before(): void
    {
        [$user, $organization] = $this->editor();

        foreach ([
            ['frequency' => 'interval', 'hours' => 6],
            ['frequency' => 'daily', 'time' => '07:15'],
            ['frequency' => 'weekly', 'time' => '20:00', 'weekdays' => [6, 7]],
        ] as $i => $schedule) {
            $this->actingAs($user)
                ->post(route('broadcasts.store', $organization), ['name' => 'Feed '.$i, 'timezone' => ''] + $schedule)
                ->assertSessionHasNoErrors();

            $stored = $organization->projects()->where('name', 'Feed '.$i)->sole()->schedule;

            // Key order is not part of the contract (MySQL JSON reorders keys).
            $this->assertEquals($schedule, array_intersect_key($stored, $schedule));
        }
    }

    public function test_the_edit_page_preselects_the_stored_frequency(): void
    {
        [$user, $organization] = $this->editor();
        $project = Project::factory()->for($organization)->create([
            'schedule' => ['frequency' => 'interval', 'hours' => 12],
        ]);

        $html = $this->actingAs($user)
            ->get(route('broadcasts.edit', [$organization, $project]))
            ->assertOk()
            ->getContent();

        $this->assertIsString($html);
        $this->assertMatchesRegularExpression('/value="interval"\s+data-schedule-frequency\s+checked/', $html);
        $this->assertStringContainsString('value="12"', $html);
        // The edit page keeps the inline timezone hint, not the create page's section copy.
        $this->assertStringNotContainsString('Schedules use this timezone, regardless of who views them.', $html);
    }

    /**
     * @return array{User, Organization}
     */
    private function editor(): array
    {
        $user = User::factory()->create();

        return [$user, Organization::factory()->withMember($user, Role::Editor)->create(['timezone' => 'UTC'])];
    }
}
