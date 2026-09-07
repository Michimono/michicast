<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomePageTest extends TestCase
{
    public function test_the_home_page_renders_the_layout(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('<header', false);
        $response->assertSee('<nav', false);
        $response->assertSee('<footer', false);
    }

    public function test_the_layout_shows_a_flash_message_from_the_session(): void
    {
        $response = $this->withSession(['success' => 'Everything worked.'])->get(route('home'));

        $response->assertOk();
        $response->assertSee('Everything worked.');
    }
}
