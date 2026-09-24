<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    //

    protected function assertAnchorIsCurrent(string $url, string $html): void
    {
        $this->assertMatchesRegularExpression(
            '/<a[^>]*href="'.preg_quote($url, '/').'"[^>]*aria-current="page"/s',
            $html,
        );
    }
}
