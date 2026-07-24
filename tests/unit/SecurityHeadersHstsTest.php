<?php

use App\Filters\SecurityHeaders;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * HSTS 는 실제 HTTPS 요청에만 붙어야 한다(dev=HTTP 제외).
 * 전역 $_SERVER 오염을 피하려고 필터를 직접 만들고 isSecure() 를 목으로 제어한다.
 *
 * @internal
 */
final class SecurityHeadersHstsTest extends CIUnitTestCase
{
    private function runFilter(bool $secure): Response
    {
        $request = $this->createMock(IncomingRequest::class);
        $request->method('isSecure')->willReturn($secure);

        $response = new Response(config('App'));
        (new SecurityHeaders())->after($request, $response);

        return $response;
    }

    public function testHstsPresentOnSecureRequest(): void
    {
        $hsts = $this->runFilter(true)->getHeaderLine('Strict-Transport-Security');

        $this->assertStringContainsString('max-age=15552000', $hsts);
        $this->assertStringContainsString('includeSubDomains', $hsts);
        $this->assertStringNotContainsString('preload', $hsts);
    }

    public function testHstsAbsentOnInsecureRequest(): void
    {
        $response = $this->runFilter(false);

        $this->assertFalse($response->hasHeader('Strict-Transport-Security'));
    }
}
