<?php

namespace Tests\Feature;

use Composer\CaBundle\CaBundle;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpCaBundleConfigurationTest extends TestCase
{
    public function test_http_clients_verify_tls_against_a_readable_ca_bundle(): void
    {
        $caPath = CaBundle::getSystemCaRootBundlePath();

        $this->assertNotSame('', $caPath);
        $this->assertTrue(is_file($caPath) || is_dir($caPath));
        $this->assertIsReadable($caPath);

        $options = Http::createPendingRequest()->getOptions();

        $this->assertArrayHasKey('verify', $options);
        $this->assertIsString($options['verify']);
        $this->assertSame($caPath, $options['verify']);
        $this->assertIsReadable($options['verify']);
    }
}
