<?php

namespace Tests\Feature;

use Tests\TestCase;

class HousePaletteTest extends TestCase
{
    public function test_the_theme_declares_the_wanderer_house_tokens(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);

        foreach ([
            '--color-house-1: #fe7af8',
            '--color-house-2: #5b8ad4',
            '--color-accent: #2e5490',
            '--color-accent-lift: #89b6d5',
            '--color-ink: #12324f',
            '--color-paper: #eef5fa',
            '--color-line: #c5d6e3',
            '--color-muted: #5a7390',
            '--color-state-skip: #5a7390',
        ] as $declaration) {
            $this->assertStringContainsString($declaration, $css);
        }

        $this->assertStringContainsString(
            'linear-gradient(112deg, var(--color-house-1) 0%, var(--color-house-2) 100%)',
            $css,
        );
    }

    public function test_the_theme_no_longer_ships_the_retired_sherbet_stops(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);
        $this->assertStringNotContainsString('--color-house-1: #ff7ba9', $css);
        $this->assertStringNotContainsString('--color-house-2: #7b8bff', $css);
        $this->assertStringNotContainsString('--color-paper: #fbf8f3', $css);
        $this->assertStringNotContainsString('--color-accent: #5a5fd8', $css);
    }

    public function test_the_dark_stub_swaps_the_wanderer_accent_pair(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);

        $dark = strstr($css, '.dark {');

        $this->assertNotFalse($dark);
        $this->assertStringContainsString('--color-accent: #89b6d5', $dark);
        $this->assertStringContainsString('--color-accent-lift: #2e5490', $dark);
    }
}
