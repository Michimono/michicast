<?php

namespace Tests\Unit\Mapping;

use App\Support\Mapping\TemplateResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TemplateResolverLiquidTest extends TestCase
{
    #[Test]
    public function default_falls_back_to_the_next_field(): void
    {
        $resolver = new TemplateResolver;

        $this->assertSame('https://a.test', $resolver->resolve('{{ linked_url | default: link }}', ['linked_url' => 'https://a.test', 'link' => 'https://b.test'])['value']);
        $this->assertSame('https://b.test', $resolver->resolve('{{ linked_url | default: link }}', ['linked_url' => null, 'link' => 'https://b.test'])['value']);
        $this->assertSame('Go: https://b.test', $resolver->resolve('Go: {{ linked_url | default: link }}', ['linked_url' => '', 'link' => 'https://b.test'])['value']);
        $this->assertSame('by someone', $resolver->resolve('by {{ author | default: "someone" }}', ['author' => ''])['value']);
    }

    #[Test]
    public function a_lone_default_chain_keeps_media_lists(): void
    {
        $resolver = new TemplateResolver;
        $media = [['type' => 'image', 'url' => 'https://example.com/a.jpg']];

        $this->assertSame($media, $resolver->resolve('{{ preview_image | default: media }}', ['preview_image' => [], 'media' => $media])['value']);
        $this->assertSame([], $resolver->resolve('{{preview_image}}', ['preview_image' => []])['value']);
    }

    #[Test]
    public function if_treats_empty_fields_as_not_set(): void
    {
        $resolver = new TemplateResolver;
        $template = "**{{ title }}**{% if text %}\n\n{{ text }}{% endif %}\n\n{{ linked_url | default: link }}";

        $this->assertSame(
            "**Dark mode when?**\n\nhttps://reddit.test/p",
            $resolver->resolve($template, ['title' => 'Dark mode when?', 'text' => '', 'linked_url' => null, 'link' => 'https://reddit.test/p'])['value'],
        );

        $this->assertSame(
            "**Dark mode when?**\n\nPlease.\n\nhttps://i.test/a.jpg",
            $resolver->resolve($template, ['title' => 'Dark mode when?', 'text' => 'Please.', 'linked_url' => 'https://i.test/a.jpg', 'link' => 'https://reddit.test/p'])['value'],
        );

        $this->assertSame('none', $resolver->resolve('{% if preview_image %}pic{% else %}none{% endif %}', ['preview_image' => []])['value']);
    }

    #[Test]
    public function whitespace_control_trims_around_tags(): void
    {
        $resolver = new TemplateResolver;

        $this->assertSame(
            "Title\nhttps://b.test",
            $resolver->resolve("{{ title }}\n{%- if author %}\nby {{ author }}\n{%- endif %}\n{{ link }}", ['title' => 'Title', 'author' => '', 'link' => 'https://b.test'])['value'],
        );
    }

    #[Test]
    public function blank_lines_are_left_as_written(): void
    {
        $resolver = new TemplateResolver;

        $this->assertSame("Title\n\n\n\nhttps://b.test", $resolver->resolve("{{ title }}\n\n{{ text }}\n\n{{ link }}", ['title' => 'Title', 'text' => '', 'link' => 'https://b.test'])['value']);
    }

    #[Test]
    public function referenced_paths_skip_keywords_and_loop_variables(): void
    {
        $resolver = new TemplateResolver;

        $this->assertEqualsCanonicalizing(
            ['text', 'title', 'link', 'media'],
            $resolver->referencedPaths('{% if text != blank %}{{ title | default: link }}{% endif %}{% for m in media %}{{ m.url }}{% endfor %}{{ media }}'),
        );

        $result = $resolver->resolve('{{ nope | default: link }}', ['link' => 'https://b.test']);
        $this->assertSame('https://b.test', $result['value']);
        $this->assertSame(['nope'], $result['missing']);
    }

    #[Test]
    public function syntax_errors_are_reported_instead_of_thrown(): void
    {
        $resolver = new TemplateResolver;

        $result = $resolver->resolve('{% if title %}open', ['title' => 'x']);

        $this->assertSame('', $result['value']);
        $this->assertSame('syntax', $result['errors'][0]->type);
        $this->assertNotNull($resolver->syntaxError('{% if title %}open'));
        $this->assertNull($resolver->syntaxError('{% if title %}{{ title }}{% endif %}'));
        $this->assertNotNull($resolver->syntaxError('{{ title | nope }}x'));
    }
}
