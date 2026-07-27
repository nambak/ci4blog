<?php

use CodeIgniter\Test\CIUnitTestCase;
use Config\Csp;

/**
 * CSP 정책의 순수 로직(문자열 조립·헤더명 분기). 필터·응답과 무관하다.
 *
 * @internal
 */
final class CspConfigTest extends CIUnitTestCase
{
    public function testHeaderNameIsReportOnlyByDefault(): void
    {
        $this->assertSame('Content-Security-Policy-Report-Only', (new Csp())->headerName());
    }

    public function testHeaderNameIsEnforceWhenReportOnlyOff(): void
    {
        $csp             = new Csp();
        $csp->reportOnly = false;

        $this->assertSame('Content-Security-Policy', $csp->headerName());
    }

    public function testCompileSerialisesDirectivesWithSemicolons(): void
    {
        $csp             = new Csp();
        $csp->directives = [
            'default-src' => ["'self'"],
            'object-src'  => ["'none'"],
        ];

        $this->assertSame("default-src 'self'; object-src 'none'", $csp->compile());
    }

    public function testCompileJoinsMultipleSourcesWithSpaces(): void
    {
        $csp             = new Csp();
        $csp->directives = ['script-src' => ["'self'", "'unsafe-inline'", 'https://example.com']];

        $this->assertSame("script-src 'self' 'unsafe-inline' https://example.com", $csp->compile());
    }

    public function testDefaultPolicyIncludesKeyDirectives(): void
    {
        $compiled = (new Csp())->compile();

        $this->assertStringContainsString("default-src 'self'", $compiled);
        $this->assertStringContainsString("object-src 'none'", $compiled);
        $this->assertStringContainsString("base-uri 'self'", $compiled);
        $this->assertStringContainsString("frame-ancestors 'none'", $compiled);
        // AdSense·폰트 호스트가 정책에 들어가 있어야 한다(라이브에서 광고·폰트가 관찰 대상).
        $this->assertStringContainsString('pagead2.googlesyndication.com', $compiled);
        $this->assertStringContainsString('fonts.gstatic.com', $compiled);
    }
}
