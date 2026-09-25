<?php

namespace Tests;

use App\Support\FakeSourceProvider;
use App\Support\SourceProviderRegistry;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(SourceProviderRegistry::class)->register('fake', FakeSourceProvider::class);
    }

    protected function assertAnchorIsCurrent(string $url, string $html): void
    {
        $this->assertMatchesRegularExpression(
            '/<a[^>]*href="'.preg_quote($url, '/').'"[^>]*aria-current="page"/s',
            $html,
        );
    }
}
