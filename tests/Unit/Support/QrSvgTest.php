<?php

namespace Tests\Unit\Support;

use App\Support\QrSvg;
use PHPUnit\Framework\TestCase;

class QrSvgTest extends TestCase
{
    public function test_it_renders_an_svg_matrix_for_an_otpauth_uri(): void
    {
        $svg = (new QrSvg)->render('otpauth://totp/Michicast:ada@example.test?secret=JBSWY3DPEHPK3PXP&issuer=Michicast');

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('role="img"', $svg);
        $this->assertStringContainsString('fill="currentColor"', $svg);
        $this->assertStringContainsString('<rect', $svg);
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/', $svg);
    }
}
