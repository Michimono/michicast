<?php

namespace Tests\Feature;

use App\Support\FontStylesheet;
use Tests\TestCase;

class StrictMarkupTest extends TestCase
{
    public function test_pages_ship_no_inline_style_or_script(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertDoesNotMatchRegularExpression('/<style\b/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<script\b(?![^>]*\bsrc=)/i', $html);
        $this->assertStringNotContainsString('browser-logger-active', $html);

        $this->assertMatchesRegularExpression(
            '#rel="stylesheet" href="[^"]*(?:/fonts\.css|/build/assets/fonts-[^"]+\.css)"#',
            $html,
        );
    }

    public function test_the_dev_font_stylesheet_route_returns_css(): void
    {
        $directory = sys_get_temp_dir().'/michicast-font-route-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $dev = $directory.'/fonts-manifest.dev.json';
        file_put_contents($dev, json_encode([
            'style' => ['inline' => "@font-face { font-family: \"Instrument Sans\"; }\n"],
        ]));

        $this->app->instance(FontStylesheet::class, new FontStylesheet(devManifest: $dev));

        $this->get(route('fonts.stylesheet'))
            ->assertOk()
            ->assertHeader('content-type', 'text/css; charset=UTF-8')
            ->assertSee('@font-face', false)
            ->assertDontSee('<style', false);
    }

    public function test_the_dev_font_stylesheet_route_is_missing_without_a_dev_manifest(): void
    {
        $this->app->instance(FontStylesheet::class, new FontStylesheet(
            devManifest: sys_get_temp_dir().'/michicast-no-dev-manifest.json',
        ));

        $this->get(route('fonts.stylesheet'))->assertNotFound();
    }
}
