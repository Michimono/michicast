<?php

namespace Tests\Unit\Support;

use App\Support\FontStylesheet;
use Tests\TestCase;

class FontStylesheetTest extends TestCase
{
    public function test_a_built_manifest_links_the_font_stylesheet_and_does_not_inline_it(): void
    {
        $directory = $this->manifestDirectory();
        $build = $directory.'/fonts-manifest.json';
        file_put_contents($build, json_encode([
            'version' => 1,
            'style' => ['file' => 'assets/fonts-test.css'],
            'preloads' => [[
                'file' => 'assets/instrument-sans-400-normal-test.woff2',
                'as' => 'font',
                'type' => 'font/woff2',
                'crossorigin' => 'anonymous',
            ]],
        ]));

        $html = (new FontStylesheet(buildManifest: $build, hotFile: $directory.'/missing-hot'))->render()->toHtml();

        $this->assertStringContainsString('rel="stylesheet"', $html);
        $this->assertStringContainsString('/build/assets/fonts-test.css', $html);
        $this->assertStringContainsString('rel="preload"', $html);
        $this->assertStringContainsString('/build/assets/instrument-sans-400-normal-test.woff2', $html);
        $this->assertStringContainsString('crossorigin="anonymous"', $html);
        $this->assertStringNotContainsString('<style', $html);
    }

    public function test_the_dev_manifest_is_linked_as_a_stylesheet_instead_of_a_style_tag(): void
    {
        $directory = $this->manifestDirectory();
        $dev = $directory.'/fonts-manifest.dev.json';
        $hot = $directory.'/hot';
        file_put_contents($hot, 'http://localhost:5173');
        file_put_contents($dev, json_encode([
            'version' => 1,
            'style' => ['inline' => "@font-face {\n  font-family: \"Instrument Sans\";\n}\n"],
            'preloads' => [[
                'url' => 'http://localhost:5173/__laravel_vite_plugin__/fonts/abc.woff2',
                'as' => 'font',
                'type' => 'font/woff2',
                'crossorigin' => 'anonymous',
            ]],
        ]));

        $fonts = new FontStylesheet(devManifest: $dev, hotFile: $hot);

        $html = $fonts->render()->toHtml();

        $this->assertStringContainsString('rel="stylesheet"', $html);
        $this->assertStringContainsString(route('fonts.stylesheet'), $html);
        $this->assertStringContainsString('http://localhost:5173/__laravel_vite_plugin__/fonts/abc.woff2', $html);
        $this->assertStringNotContainsString('<style', $html);
        $this->assertStringContainsString('font-family: "Instrument Sans"', (string) $fonts->devCss());
    }

    public function test_dev_css_is_absent_when_there_is_no_dev_manifest(): void
    {
        $fonts = new FontStylesheet(
            buildManifest: sys_get_temp_dir().'/michicast-missing-fonts-manifest.json',
            devManifest: sys_get_temp_dir().'/michicast-missing-fonts-manifest.dev.json',
            hotFile: sys_get_temp_dir().'/michicast-missing-hot',
        );

        $this->assertNull($fonts->devCss());
        $this->assertSame('', $fonts->render()->toHtml());
    }

    private function manifestDirectory(): string
    {
        $directory = sys_get_temp_dir().'/michicast-fonts-'.bin2hex(random_bytes(4));
        mkdir($directory);

        return $directory;
    }
}
