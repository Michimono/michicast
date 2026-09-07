<?php

namespace Tests\Feature;

use Tests\TestCase;

class InputComponentTest extends TestCase
{
    public function test_the_input_shows_the_validation_error_for_its_own_field(): void
    {
        $this->withViewErrors([
            'email' => 'The email field is required.',
            'name' => 'The name field is required.',
        ]);

        $view = $this->blade('<x-input name="email" label="Email address" />');

        $view->assertSee('Email address');
        $view->assertSee('The email field is required.');
        $view->assertDontSee('The name field is required.');
    }
}
