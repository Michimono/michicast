<?php

namespace Tests\Unit\Mapping;

use App\Support\Mapping\TemplateResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TemplateResolverTest extends TestCase
{
    #[Test]
    public function it_interpolates_whitespace_variants_and_literals(): void
    {
        $resolver = new TemplateResolver;
        $context = ['title' => 'Hello', 'body' => 'World'];

        $this->assertSame('Hello', $resolver->resolve('{{title}}', $context)['value']);
        $this->assertSame('Hello', $resolver->resolve('{{ title }}', $context)['value']);
        $this->assertSame('Prefix: Hello!', $resolver->resolve('Prefix: {{title}}!', $context)['value']);
        $this->assertSame("Hello\nWorld", $resolver->resolve("{{title}}\n{{body}}", $context)['value']);
        $this->assertSame('Hello / World', $resolver->resolve('{{title}} / {{body}}', $context)['value']);
    }

    #[Test]
    public function missing_vs_empty_and_nested_paths(): void
    {
        $resolver = new TemplateResolver;
        $context = [
            'title' => '',
            'meta' => ['author' => 'Ada'],
        ];

        $empty = $resolver->resolve('X{{title}}Y', $context);
        $this->assertSame('XY', $empty['value']);
        $this->assertSame([], $empty['missing']);

        $missing = $resolver->resolve('{{nope}}', $context);
        $this->assertSame('', $missing['value']);
        $this->assertSame(['nope'], $missing['missing']);

        $nested = $resolver->resolve('by {{meta.author}}', $context);
        $this->assertSame('by Ada', $nested['value']);
    }

    #[Test]
    public function single_token_preserves_non_scalar_values(): void
    {
        $resolver = new TemplateResolver;
        $media = [['type' => 'image', 'url' => 'https://example.com/a.jpg']];

        $result = $resolver->resolve('{{media}}', ['media' => $media]);

        $this->assertSame($media, $result['value']);
    }
}
