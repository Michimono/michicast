<?php

namespace Tests\Unit;

use App\Models\ContentItem;
use App\Support\BroadcastTransforms\BroadcastTransformer;
use App\Support\BroadcastTransforms\TemplatePlaceholders;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BroadcastTransformerTest extends TestCase
{
    private BroadcastTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transformer = new BroadcastTransformer;
        $this->travelTo(Carbon::parse('2026-03-15 12:00:00', 'UTC'));
    }

    public function test_each_placeholder_renders_from_the_content_item(): void
    {
        $item = $this->item([
            'title' => 'Pocket drop',
            'body' => '<p>Longer body text for the summary placeholder and full body.</p>',
            'author' => 'Official',
            'canonical_url' => 'https://example.com/posts/1',
            'published_at' => Carbon::parse('2026-03-14 09:00:00', 'UTC'),
        ]);

        $message = $this->transformer->transform(
            $item,
            [
                'template' => '{{title}}|{{body}}|{{summary}}|{{url}}|{{author}}|{{published}}|{{source}}',
            ],
            'Pocket feed',
        );

        $this->assertStringContainsString('Pocket drop', $message->body);
        $this->assertStringContainsString('<p>Longer body text', $message->body);
        $this->assertStringContainsString('Longer body text for the summary', $message->body);
        $this->assertStringContainsString('https://example.com/posts/1', $message->body);
        $this->assertStringContainsString('Official', $message->body);
        $this->assertStringContainsString('14 Mar 2026', $message->body);
        $this->assertStringContainsString('Pocket feed', $message->body);
        $this->assertSame('https://example.com/posts/1', $message->link);
    }

    public function test_a_broadcast_with_no_configured_template_uses_the_default(): void
    {
        $item = $this->item(['title' => 'Headline', 'body' => 'Story']);

        $message = $this->transformer->transform($item, null);

        $this->assertSame("Headline\n\nStory", $message->body);
        $this->assertSame(TemplatePlaceholders::DEFAULT_TEMPLATE, $this->transformer->template(null));
    }

    public function test_strip_html_removes_markup(): void
    {
        $item = $this->item(['title' => 'T', 'body' => '<b>Bold</b> text']);

        $message = $this->transformer->transform($item, [
            'template' => '{{body}}',
            'rules' => [['type' => 'strip_html']],
        ]);

        $this->assertSame('Bold text', $message->body);
    }

    public function test_truncate_stops_on_a_word_boundary(): void
    {
        $item = $this->item(['title' => 'T', 'body' => 'The quick brown fox jumps']);

        $message = $this->transformer->transform($item, [
            'template' => '{{body}}',
            'rules' => [['type' => 'truncate', 'length' => 12]],
        ]);

        $this->assertSame('The quick...', $message->body);
        $this->assertStringNotContainsString('brown', $message->body);
    }

    public function test_prepend_and_append_wrap_the_message(): void
    {
        $item = $this->item(['title' => 'News', 'body' => 'Body']);

        $message = $this->transformer->transform($item, [
            'template' => '{{title}}',
            'rules' => [
                ['type' => 'prepend', 'value' => '>> '],
                ['type' => 'append', 'value' => ' <<'],
            ],
        ]);

        $this->assertSame('>> News <<', $message->body);
    }

    public function test_replace_applies_a_regular_expression(): void
    {
        $item = $this->item(['title' => 'Hello world', 'body' => 'x']);

        $message = $this->transformer->transform($item, [
            'template' => '{{title}}',
            'rules' => [
                ['type' => 'replace', 'pattern' => 'world', 'replacement' => 'Pocket'],
            ],
        ]);

        $this->assertSame('Hello Pocket', $message->body);
    }

    public function test_rules_compose_in_list_order(): void
    {
        $item = $this->item([
            'title' => 'T',
            'body' => '<p>Alpha Beta Gamma Delta</p>',
        ]);

        $message = $this->transformer->transform($item, [
            'template' => '{{body}}',
            'rules' => [
                ['type' => 'strip_html'],
                ['type' => 'truncate', 'length' => 11],
                ['type' => 'prepend', 'value' => 'Go: '],
            ],
        ]);

        $this->assertSame('Go: Alpha...', $message->body);
    }

    public function test_template_input_is_substituted_and_never_executed_as_php_or_blade(): void
    {
        $item = $this->item(['title' => 'Safe', 'body' => 'Body']);

        $message = $this->transformer->transform($item, [
            'template' => "{{title}}\n<?php echo 'hack'; ?>\n@if(true) blade @endif",
        ]);

        $this->assertStringContainsString('Safe', $message->body);
        $this->assertStringContainsString("<?php echo 'hack'; ?>", $message->body);
        $this->assertStringContainsString('@if(true) blade @endif', $message->body);
        $this->assertStringNotContainsString('hacked', $message->body);
    }

    public function test_unknown_placeholders_are_detected_for_validation(): void
    {
        $this->assertSame(['evil'], TemplatePlaceholders::unknownIn('{{title}} {{evil}}'));
        $this->assertSame([], TemplatePlaceholders::unknownIn('{{title}} {{url}}'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function item(array $attributes = []): ContentItem
    {
        $item = new ContentItem;
        $item->forceFill([
            'title' => 'Title',
            'body' => 'Body',
            'author' => 'Author',
            'canonical_url' => 'https://example.com/item',
            'published_at' => Carbon::parse('2026-03-15 11:00:00', 'UTC'),
            'media' => [],
            ...$attributes,
        ]);

        return $item;
    }
}
