<?php

namespace Tests\Feature;

use App\Enums\ConfigurationFieldType;
use App\Exceptions\UnknownSourceProviderException;
use App\Models\ContentItem;
use App\Models\Project;
use App\Support\FakeSourceProvider;
use App\Support\SourceDefinition;
use App\Support\SourceProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class SourceProviderRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_configured_provider_is_resolved_by_its_type_string(): void
    {
        $registry = $this->registry();

        $this->assertTrue($registry->has('fake'));
        $this->assertInstanceOf(FakeSourceProvider::class, $registry->get('fake'));
        $this->assertSame('fake', $registry->get('fake')->type());
    }

    public function test_a_provider_can_be_registered_at_runtime(): void
    {
        $registry = $this->registry();
        $registry->register('secondary', FakeSourceProvider::class);

        $this->assertContains('secondary', $registry->types());
        $this->assertInstanceOf(FakeSourceProvider::class, $registry->get('secondary'));
    }

    public function test_an_unknown_type_fails_with_a_clear_catchable_error(): void
    {
        $this->expectException(UnknownSourceProviderException::class);
        $this->expectExceptionMessage('No source provider is registered for the type [nope].');

        $this->registry()->get('nope');
    }

    public function test_the_registry_offers_the_available_types_for_the_source_form(): void
    {
        $options = $this->registry()->options();

        $this->assertArrayHasKey('rss', $options);
        $this->assertSame('RSS / Atom feed', $options['rss']);
        $this->assertArrayNotHasKey('fake', $options);
    }

    public function test_capabilities_are_queried_without_knowing_the_provider(): void
    {
        $this->assertTrue($this->registry()->capabilities('fake')->supportsConnectionTest);
    }

    public function test_a_provider_declares_the_fields_a_form_renders_for_it(): void
    {
        $fields = $this->registry()->configurationFields('fake');

        $this->assertCount(1, $fields);
        $this->assertSame('feed_url', $fields[0]->name);
        $this->assertSame('Feed URL', $fields[0]->label);
        $this->assertSame(ConfigurationFieldType::Url, $fields[0]->type);
        $this->assertArrayHasKey($fields[0]->name, $this->registry()->configurationRules('fake'));
    }

    public function test_configuration_is_validated_against_the_provider_rules(): void
    {
        $rules = $this->registry()->configurationRules('fake');

        $this->assertTrue(Validator::make(['feed_url' => 'https://example.com/feed.xml'], $rules)->passes());

        $validator = Validator::make(['feed_url' => 'not-a-url'], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('feed_url', $validator->errors()->toArray());
    }

    public function test_a_refused_connection_comes_back_as_a_result(): void
    {
        $provider = $this->registry()->get('fake');

        $reached = $provider->testConnection($this->definition(['feed_url' => 'https://example.com/feed.xml']));
        $refused = $provider->testConnection($this->definition([]));

        $this->assertTrue($reached->successful);
        $this->assertFalse($refused->successful);
        $this->assertSame('The fake source has no feed URL to reach.', $refused->message);
    }

    public function test_a_provider_returns_normalized_items_without_saving_them(): void
    {
        $project = Project::factory()->create();

        $items = $this->registry()->get('fake')->fetch($this->definition([
            'feed_url' => 'https://example.com/feed.xml',
            'items' => [$this->item()],
        ], $project->id));

        $this->assertCount(1, $items);
        $this->assertInstanceOf(ContentItem::class, $items[0]);
        $this->assertFalse($items[0]->exists);
        $this->assertSame('ext:post-1', $items[0]->identity_key);
        $this->assertNotEmpty($items[0]->content_hash);
        $this->assertSame(['provider' => 'fake'], $items[0]->metadata);
        $this->assertDatabaseCount('content_items', 0);
    }

    public function test_fetching_the_same_source_twice_persists_one_item_per_identity(): void
    {
        $project = Project::factory()->create();
        $definition = $this->definition([
            'feed_url' => 'https://example.com/feed.xml',
            'items' => [$this->item()],
        ], $project->id);

        $provider = $this->registry()->get('fake');

        foreach ($provider->fetch($definition) as $item) {
            $item->persist();
        }

        foreach ($provider->fetch($definition) as $item) {
            $item->persist();
        }

        $this->assertDatabaseCount('content_items', 1);
        $this->assertDatabaseHas('content_items', [
            'project_id' => $project->id,
            'source_id' => 12,
            'external_id' => 'post-1',
            'title' => 'Hello',
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function definition(array $config, int $projectId = 1, int $sourceId = 12): SourceDefinition
    {
        return new SourceDefinition('fake', $config, $projectId, $sourceId);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(): array
    {
        return [
            'external_id' => 'post-1',
            'canonical_url' => 'https://example.com/posts/1',
            'title' => 'Hello',
            'body' => 'World',
            'author' => 'Ada',
            'published_at' => '2026-03-07T12:00:00Z',
        ];
    }

    private function registry(): SourceProviderRegistry
    {
        return $this->app->make(SourceProviderRegistry::class);
    }
}
