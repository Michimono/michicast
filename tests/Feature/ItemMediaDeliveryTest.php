<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Models\Broadcast;
use App\Models\ContentDelivery;
use App\Models\ContentItem;
use App\Models\Destination;
use App\Models\Project;
use App\Models\Source;
use App\Support\DestinationMediaSupport;
use App\Support\DestinationProviderCapabilities;
use App\Support\DestinationProviderRegistry;
use App\Support\ExecutingProjectRunner;
use App\Support\Mapping\DefaultMappings;
use App\Support\RecordingDestinationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ItemMediaDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const MEDIA = [
        ['type' => 'video', 'url' => 'https://cdn.example.com/clip.mp4'],
        ['type' => 'image', 'url' => 'https://cdn.example.com/one.jpg', 'alt' => 'One'],
        ['type' => 'image', 'url' => 'https://cdn.example.com/two.jpg'],
    ];

    public function test_a_delivery_carries_the_items_media_in_order(): void
    {
        $project = $this->projectWithItem($this->item(['media' => self::MEDIA]));

        $this->runner()->run($project);

        $message = $this->recording()->published()[0];
        $this->assertSame('Launch day', $message->body);
        $this->assertSame(
            ['https://cdn.example.com/clip.mp4', 'https://cdn.example.com/one.jpg', 'https://cdn.example.com/two.jpg'],
            array_column($message->media, 'url'),
        );
        $this->assertSame(['video', 'image', 'image'], array_column($message->media, 'type'));

        $delivery = ContentDelivery::query()->sole();
        $this->assertSame(DeliveryStatus::Delivered, $delivery->status);
        $this->assertSame(
            ['https://cdn.example.com/clip.mp4', 'https://cdn.example.com/one.jpg', 'https://cdn.example.com/two.jpg'],
            array_column($delivery->published_payload['message']['media'], 'url'),
        );
    }

    public function test_the_default_mapping_attaches_the_items_media(): void
    {
        $project = $this->projectWithItem($this->item(['media' => self::MEDIA]), [
            'transform' => null,
            'mapping' => (new DefaultMappings)->for('fake', 'recording')?->toArray(),
        ]);

        $this->runner()->run($project);

        $message = $this->recording()->published()[0];
        $this->assertStringContainsString('Launch day', $message->body);
        $this->assertSame(
            ['https://cdn.example.com/clip.mp4', 'https://cdn.example.com/one.jpg', 'https://cdn.example.com/two.jpg'],
            array_column($message->media, 'url'),
        );
    }

    public function test_a_destination_receives_only_the_media_it_declared(): void
    {
        $this->app->make(DestinationProviderRegistry::class)->register('recording', OnePictureDestinationProvider::class);
        $project = $this->projectWithItem($this->item(['media' => self::MEDIA]));

        $this->runner()->run($project);

        // The first picture only: the video is not a type it takes, and it takes one.
        $message = $this->recording()->published()[0];
        $this->assertSame(['https://cdn.example.com/one.jpg'], array_column($message->media, 'url'));
        $this->assertSame('One', $message->media[0]['alt']);
        $this->assertSame('Launch day', $message->body);
        $this->assertSame(DeliveryStatus::Delivered, ContentDelivery::query()->sole()->status);
    }

    public function test_a_text_only_destination_still_gets_the_text(): void
    {
        $this->app->make(DestinationProviderRegistry::class)->register('recording', TextOnlyDestinationProvider::class);
        $project = $this->projectWithItem($this->item(['media' => self::MEDIA]));

        $this->runner()->run($project);

        $message = $this->recording()->published()[0];
        $this->assertSame([], $message->media);
        $this->assertSame('Launch day', $message->body);
        $this->assertSame(DeliveryStatus::Delivered, ContentDelivery::query()->sole()->status);
    }

    public function test_media_that_appears_on_a_delivered_item_does_not_send_it_again(): void
    {
        $project = $this->projectWithItem($this->item());

        $this->runner()->run($project);

        $this->assertCount(1, $this->recording()->published());
        $this->assertSame([], $this->recording()->published()[0]->media);
        $this->assertNull(ContentItem::query()->sole()->media);

        // The same entry now comes back with a picture, as it does for an
        // existing feed once the source starts reading media.
        $source = $project->sources()->sole();
        $source->config = [
            'feed_url' => 'https://example.com/feed.xml',
            'items' => [$this->item(['media' => self::MEDIA])],
        ];
        $source->save();

        $this->runner()->run($project);

        $this->assertCount(1, $this->recording()->published());
        $this->assertSame(1, ContentItem::query()->count());
        $this->assertCount(3, ContentItem::query()->sole()->media ?? []);
        $this->assertSame(1, ContentDelivery::query()->count());
        $this->assertSame(DeliveryStatus::Delivered, ContentDelivery::query()->sole()->status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function item(array $overrides = []): array
    {
        return [
            'external_id' => 'launch-1',
            'title' => 'Launch day',
            'canonical_url' => 'https://example.com/launch-1',
            'published_at' => '2026-03-15T12:00:00Z',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $broadcast
     */
    private function projectWithItem(array $item, array $broadcast = []): Project
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $project = Project::factory()->due()->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [$item],
            ],
        ]);
        $destination = Destination::factory()->create([
            'project_id' => $project->getKey(),
        ]);
        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'transform' => ['template' => '{{title}}'],
            'created_at' => Carbon::parse('2026-03-14 10:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-03-14 10:00:00', 'UTC'),
            ...$broadcast,
        ]);

        return $project;
    }

    private function runner(): ExecutingProjectRunner
    {
        return $this->app->make(ExecutingProjectRunner::class);
    }

    private function recording(): RecordingDestinationProvider
    {
        $provider = $this->app->make(DestinationProviderRegistry::class)->get('recording');
        $this->assertInstanceOf(RecordingDestinationProvider::class, $provider);

        return $provider;
    }
}

class OnePictureDestinationProvider extends RecordingDestinationProvider
{
    public function capabilities(): DestinationProviderCapabilities
    {
        return new DestinationProviderCapabilities(
            supportsConnectionTest: true,
            media: DestinationMediaSupport::of(['image'], 1),
        );
    }
}

class TextOnlyDestinationProvider extends RecordingDestinationProvider
{
    public function capabilities(): DestinationProviderCapabilities
    {
        return new DestinationProviderCapabilities(supportsConnectionTest: true);
    }
}
