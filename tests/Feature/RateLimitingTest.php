<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_organization_shares_one_allowance_that_another_organization_never_spends(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $busy = Organization::factory()->withMember($first, Role::Editor)->withMember($second, Role::Editor)->create();
        $sources = $this->sourcesIn($busy, 6);

        // Five targets at the per-member limit of six spend the busy organization's thirty.
        foreach ($sources->take(5) as $index => $source) {
            $member = $index < 3 ? $first : $second;

            for ($attempt = 1; $attempt <= 6; $attempt++) {
                $this->connectionTest($member, $busy, $source)->assertOk();
            }
        }

        // A fresh member/target pair is still refused: the organization bucket is empty.
        $this->connectionTest($second, $busy, $sources->last())
            ->assertTooManyRequests()
            ->assertSee('Too many attempts');

        $outsider = User::factory()->create();
        $quiet = Organization::factory()->withMember($outsider, Role::Editor)->create();

        $this->connectionTest($outsider, $quiet, $this->sourcesIn($quiet, 1)->sole())->assertOk();
    }

    public function test_a_throttled_page_says_when_to_try_again(): void
    {
        [$user, $organization, $source] = $this->editorWithSource();

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->connectionTest($user, $organization, $source)->assertOk();
        }

        $this->connectionTest($user, $organization, $source)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertSee('Too many attempts')
            ->assertSee('Try again in');
    }

    public function test_a_throttled_json_request_gets_a_readable_message(): void
    {
        [$user, $organization, $source] = $this->editorWithSource();

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->connectionTest($user, $organization, $source)->assertOk();
        }

        $this->actingAs($user)
            ->postJson(route('source-connection-tests.store', [$organization, $source]), $this->payload($source))
            ->assertTooManyRequests()
            ->assertJsonPath('message', fn (string $message): bool => str_starts_with($message, 'Too many attempts. Try again in '))
            ->assertJsonStructure(['message', 'retry_after']);
    }

    public function test_run_now_is_rate_limited(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, Role::Editor)->create();
        $project = Project::factory()->for($organization)->active()->create();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->actingAs($user)
                ->post(route('due-broadcasts.store', [$organization, $project]))
                ->assertRedirect(route('broadcasts.show', [$organization, $project]));
        }

        $this->actingAs($user)
            ->post(route('due-broadcasts.store', [$organization, $project]))
            ->assertTooManyRequests();
    }

    /**
     * @return array{User, Organization, Source}
     */
    private function editorWithSource(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, Role::Editor)->create();

        return [$user, $organization, $this->sourcesIn($organization, 1)->sole()];
    }

    /**
     * @return Collection<int, Source>
     */
    private function sourcesIn(Organization $organization, int $count): Collection
    {
        return Source::factory()
            ->count($count)
            ->for(Project::factory()->for($organization))
            ->create(['config' => ['feed_url' => 'https://example.com/feed.xml']]);
    }

    private function connectionTest(User $user, Organization $organization, Source $source): TestResponse
    {
        return $this->actingAs($user)
            ->post(route('source-connection-tests.store', [$organization, $source]), $this->payload($source));
    }

    /**
     * @return array{name: string, config: array{feed_url: string}}
     */
    private function payload(Source $source): array
    {
        return [
            'name' => $source->name,
            'config' => ['feed_url' => 'https://example.com/feed.xml'],
        ];
    }
}
