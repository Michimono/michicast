<?php

namespace Tests\Unit\Mapping;

use App\Support\Mapping\TemplateResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TemplateResolverFallbackTest extends TestCase
{
    #[Test]
    public function a_fallback_uses_the_first_term_with_a_value(): void
    {
        $resolver = new TemplateResolver;

        $this->assertSame('https://a.test', $resolver->resolve('{{linked_url || link}}', ['linked_url' => 'https://a.test', 'link' => 'https://b.test'])['value']);
        $this->assertSame('https://b.test', $resolver->resolve('{{ linked_url || link }}', ['linked_url' => null, 'link' => 'https://b.test'])['value']);
        $this->assertSame('https://b.test', $resolver->resolve('{{linked_url||link}}', ['linked_url' => '  ', 'link' => 'https://b.test'])['value']);
        $this->assertSame('Go: https://b.test', $resolver->resolve('Go: {{linked_url || link}}', ['linked_url' => '', 'link' => 'https://b.test'])['value']);
    }

    #[Test]
    public function quoted_text_works_as_a_last_resort(): void
    {
        $resolver = new TemplateResolver;

        $this->assertSame('someone', $resolver->resolve('{{author || "someone"}}', ['author' => ''])['value']);
        $this->assertSame('by say "hi"', $resolver->resolve('by {{author || "say \"hi\""}}', ['author' => null])['value']);
        $this->assertSame('Ada', $resolver->resolve('{{author || "someone"}}', ['author' => 'Ada'])['value']);
    }

    #[Test]
    public function a_single_token_fallback_keeps_non_text_values(): void
    {
        $resolver = new TemplateResolver;
        $media = [['type' => 'image', 'url' => 'https://example.com/a.jpg']];

        $this->assertSame($media, $resolver->resolve('{{preview_image || media}}', ['preview_image' => [], 'media' => $media])['value']);
        $this->assertSame([], $resolver->resolve('{{preview_image || media}}', ['preview_image' => [], 'media' => []])['value']);
    }

    #[Test]
    public function missing_terms_are_reported_but_fall_through(): void
    {
        $resolver = new TemplateResolver;

        $result = $resolver->resolve('{{nope || link}}', ['link' => 'https://b.test']);

        $this->assertSame('https://b.test', $result['value']);
        $this->assertSame(['nope'], $result['missing']);
        $this->assertSame(['nope', 'link'], $resolver->referencedPaths('{{nope || link || "x"}} {{link}}'));
    }

    #[Test]
    public function lines_whose_fields_are_all_empty_are_left_out(): void
    {
        $resolver = new TemplateResolver;
        $template = "**{{title}}**\n\n{{text}}\n\n{{linked_url}}\n\nRead more: {{link}}";

        $this->assertSame(
            "**Dark mode when?**\n\nRead more: https://reddit.test/p",
            $resolver->resolve($template, ['title' => 'Dark mode when?', 'text' => '', 'linked_url' => null, 'link' => 'https://reddit.test/p'])['value'],
        );

        $this->assertSame(
            "**Dark mode when?**\n\nPlease.\n\nhttps://i.test/a.jpg\n\nRead more: https://reddit.test/p",
            $resolver->resolve($template, ['title' => 'Dark mode when?', 'text' => 'Please.', 'linked_url' => 'https://i.test/a.jpg', 'link' => 'https://reddit.test/p'])['value'],
        );

        $this->assertSame(
            'Please.',
            $resolver->resolve($template, ['title' => '', 'text' => 'Please.', 'linked_url' => '', 'link' => ''])['value'],
        );
    }

    #[Test]
    public function a_dropped_line_without_blank_lines_around_it_leaves_no_gap(): void
    {
        $resolver = new TemplateResolver;

        $this->assertSame(
            "Title\nhttps://b.test",
            $resolver->resolve("{{title}}\nby {{author}}\n{{link}}", ['title' => 'Title', 'author' => '', 'link' => 'https://b.test'])['value'],
        );
    }

    #[Test]
    public function blank_lines_the_user_wrote_are_kept(): void
    {
        $resolver = new TemplateResolver;

        $this->assertSame(
            "Title\n\n\nBody\n",
            $resolver->resolve("{{title}}\n\n\n{{body}}\n", ['title' => 'Title', 'body' => 'Body'])['value'],
        );
    }

    #[Test]
    public function single_line_values_are_not_dropped(): void
    {
        $resolver = new TemplateResolver;

        $this->assertSame('Read more: ', $resolver->resolve('Read more: {{link}}', ['link' => ''])['value']);
    }
}
