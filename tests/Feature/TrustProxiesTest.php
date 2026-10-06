<?php

namespace Tests\Feature;

use Tests\TestCase;

class TrustProxiesTest extends TestCase
{
    public function test_a_forwarded_https_request_generates_https_urls(): void
    {
        $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'app.example.com',
            'X-Forwarded-Port' => '443',
        ])->get(route('home'));

        $this->assertTrue(request()->secure());
        $this->assertSame('https', request()->getScheme());
        $this->assertStringStartsWith('https://app.example.com', url('/'));
    }
}
