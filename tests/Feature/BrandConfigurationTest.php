<?php

namespace Tests\Feature;

use Tests\TestCase;

class BrandConfigurationTest extends TestCase
{
    public function test_the_home_page_renders_the_configured_brand(): void
    {
        config([
            'app.name' => 'Configured Product',
            'michicast.brand' => 'Configured Brand',
            'michicast.support_email' => 'configured@example.test',
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Configured Product');
        $response->assertSee('Configured Brand');
        $response->assertSee('configured@example.test');
    }
}
