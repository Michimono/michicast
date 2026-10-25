<?php

namespace Tests\Unit;

use App\Models\ContentItem;
use App\Support\BroadcastFilters\BoundedRegex;
use App\Support\BroadcastFilters\BroadcastFilterEvaluator;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BroadcastFilterTest extends TestCase
{
    private BroadcastFilterEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new BroadcastFilterEvaluator;
        $this->travelTo(Carbon::parse('2026-03-15 12:00:00', 'UTC'));
    }

    public function test_a_broadcast_with_no_rules_passes_everything(): void
    {
        $item = $this->item(['title' => 'Anything']);

        $this->assertTrue($this->evaluator->evaluate(null, $item)->passed);
        $this->assertTrue($this->evaluator->evaluate(['rules' => []], $item)->passed);
        $this->assertNull($this->evaluator->evaluate(null, $item)->skipReason);
    }

    public function test_contains_matches_and_rejects_on_the_chosen_field(): void
    {
        $item = $this->item(['title' => 'Pokemon news', 'body' => 'Weekly update']);

        $pass = $this->evaluator->evaluate([
            'rules' => [['type' => 'contains', 'field' => 'title', 'value' => 'pokemon']],
        ], $item);

        $fail = $this->evaluator->evaluate([
            'rules' => [['type' => 'contains', 'field' => 'body', 'value' => 'pokemon']],
        ], $item);

        $this->assertTrue($pass->passed);
        $this->assertFalse($fail->passed);
        $this->assertSame('Body does not contain “pokemon”', $fail->skipReason);
    }

    public function test_does_not_contain_rejects_when_the_needle_is_present(): void
    {
        $item = $this->item(['title' => 'Spoiler ahead']);

        $decision = $this->evaluator->evaluate([
            'rules' => [['type' => 'does_not_contain', 'field' => 'title', 'value' => 'spoiler']],
        ], $item);

        $this->assertFalse($decision->passed);
        $this->assertSame('Title contains “spoiler”', $decision->skipReason);
    }

    public function test_contains_and_equals_can_use_author_or_body(): void
    {
        $item = $this->item(['title' => 'Plain', 'body' => 'News: Pocket drop', 'author' => 'Ash']);

        $this->assertTrue($this->evaluator->evaluate([
            'rules' => [['type' => 'contains', 'field' => 'author', 'value' => 'ash']],
        ], $item)->passed);

        $this->assertTrue($this->evaluator->evaluate([
            'rules' => [['type' => 'author_equals', 'field' => 'title', 'value' => 'Plain']],
        ], $item)->passed);

        $match = $this->evaluator->evaluate([
            'rules' => [['type' => 'title_matches', 'field' => 'body', 'value' => '^News:']],
        ], $item);

        $this->assertTrue($match->passed);
        $this->assertFalse($this->evaluator->evaluate([
            'rules' => [['type' => 'title_matches', 'field' => 'body', 'value' => '^Alert:']],
        ], $item)->passed);
    }

    public function test_title_matches_uses_a_regular_expression(): void
    {
        $item = $this->item(['title' => 'News: Pocket drop']);

        $pass = $this->evaluator->evaluate([
            'rules' => [['type' => 'title_matches', 'value' => '^News:']],
        ], $item);

        $fail = $this->evaluator->evaluate([
            'rules' => [['type' => 'title_matches', 'value' => '^Alert:']],
        ], $item);

        $this->assertTrue($pass->passed);
        $this->assertFalse($fail->passed);
        $this->assertSame('Title does not match “^Alert:”', $fail->skipReason);
    }

    public function test_published_after_requires_a_recent_item(): void
    {
        $recent = $this->item(['published_at' => Carbon::parse('2026-03-15 10:00:00', 'UTC')]);
        $old = $this->item(['published_at' => Carbon::parse('2026-03-10 10:00:00', 'UTC')]);

        $this->assertTrue($this->evaluator->evaluate([
            'rules' => [['type' => 'published_after', 'hours' => 24]],
        ], $recent)->passed);

        $decision = $this->evaluator->evaluate([
            'rules' => [['type' => 'published_after', 'hours' => 24]],
        ], $old);

        $this->assertFalse($decision->passed);
        $this->assertSame('Not published within the last 24 hours', $decision->skipReason);
    }

    public function test_published_before_requires_an_older_item(): void
    {
        $recent = $this->item(['published_at' => Carbon::parse('2026-03-15 10:00:00', 'UTC')]);
        $old = $this->item(['published_at' => Carbon::parse('2026-03-10 10:00:00', 'UTC')]);

        $this->assertTrue($this->evaluator->evaluate([
            'rules' => [['type' => 'published_before', 'hours' => 24]],
        ], $old)->passed);

        $decision = $this->evaluator->evaluate([
            'rules' => [['type' => 'published_before', 'hours' => 24]],
        ], $recent);

        $this->assertFalse($decision->passed);
        $this->assertSame('Not published more than 24 hours ago', $decision->skipReason);
    }

    public function test_author_equals_is_case_insensitive(): void
    {
        $item = $this->item(['author' => 'OfficialAccount']);

        $this->assertTrue($this->evaluator->evaluate([
            'rules' => [['type' => 'author_equals', 'value' => 'officialaccount']],
        ], $item)->passed);

        $decision = $this->evaluator->evaluate([
            'rules' => [['type' => 'author_equals', 'value' => 'someone-else']],
        ], $item);

        $this->assertFalse($decision->passed);
        $this->assertSame('Author is not “someone-else”', $decision->skipReason);
    }

    public function test_several_rules_combine_with_and_and_name_the_first_failure(): void
    {
        $item = $this->item([
            'title' => 'Pokemon news',
            'author' => 'Official',
            'published_at' => Carbon::parse('2026-03-15 10:00:00', 'UTC'),
        ]);

        $pass = $this->evaluator->evaluate([
            'rules' => [
                ['type' => 'contains', 'field' => 'title', 'value' => 'Pokemon'],
                ['type' => 'author_equals', 'value' => 'Official'],
                ['type' => 'published_after', 'hours' => 48],
            ],
        ], $item);

        $fail = $this->evaluator->evaluate([
            'rules' => [
                ['type' => 'contains', 'field' => 'title', 'value' => 'Pokemon'],
                ['type' => 'author_equals', 'value' => 'Wrong'],
                ['type' => 'published_after', 'hours' => 48],
            ],
        ], $item);

        $this->assertTrue($pass->passed);
        $this->assertFalse($fail->passed);
        $this->assertSame('Author is not “Wrong”', $fail->skipReason);
    }

    public function test_an_invalid_pattern_is_rejected_by_the_bounded_regex_helper(): void
    {
        $this->assertFalse(BoundedRegex::isValid('('));
        $this->assertTrue(BoundedRegex::isValid('^News:'));
    }

    #[DataProvider('missingPublishedAtCases')]
    public function test_age_rules_fail_when_published_at_is_missing(string $type): void
    {
        $item = $this->item(['published_at' => null]);

        $decision = $this->evaluator->evaluate([
            'rules' => [['type' => $type, 'hours' => 24]],
        ], $item);

        $this->assertFalse($decision->passed);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function missingPublishedAtCases(): array
    {
        return [
            'after' => ['published_after'],
            'before' => ['published_before'],
        ];
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
            'published_at' => Carbon::parse('2026-03-15 11:00:00', 'UTC'),
            ...$attributes,
        ]);

        return $item;
    }
}
