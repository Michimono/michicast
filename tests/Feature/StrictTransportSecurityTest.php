<?php

namespace Tests\Feature;

use Tests\TestCase;

class StrictTransportSecurityTest extends TestCase
{
    public function test_https_responses_include_strict_transport_security(): void
    {
        $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'app.example.com',
            'X-Forwarded-Port' => '443',
        ])->get(route('home'))
            ->assertOk()
            ->assertHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
    }

    public function test_http_responses_do_not_include_strict_transport_security(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');
    }
}
