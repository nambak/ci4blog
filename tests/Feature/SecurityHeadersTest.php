<?php

namespace Tests\Feature;

use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Csp;
use Config\Services;

/**
 * 전역 after 필터가 모든 응답에 보안 헤더를 부착하는지 검증한다.
 * 배선(별칭 등록 + globals.after)까지 함께 검증하려고 실제 라우트를 call() 로 호출한다.
 * GET 만 쓰므로 CSRF 트레잇은 불필요.
 */
final class SecurityHeadersTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    // 공통 헤더가 auth()->loggedIn() 을 호출하므로 Shield/Settings 까지 마이그레이션한다.
    protected $namespace = null;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();
        // 응답 서비스는 shared 라 앞 테스트가 붙인 헤더가 다음 테스트로 샌다.
        // CSP off 케이스가 "헤더 없음"을 검증하므로 매 테스트 전에 초기화한다.
        Services::resetSingle('response');
    }

    public function testResponseHasNosniffHeader(): void
    {
        $result = $this->call('GET', 'about');

        $result->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function testResponseHasReferrerPolicyHeader(): void
    {
        $result = $this->call('GET', 'about');

        $result->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function testResponseHasFrameOptionsDeny(): void
    {
        $result = $this->call('GET', 'about');

        $result->assertHeader('X-Frame-Options', 'DENY');
    }

    protected function tearDown(): void
    {
        // Csp 주입이 뒤 테스트로 새지 않도록 되돌린다.
        Factories::reset('config');
        parent::tearDown();
    }

    private function injectCsp(bool $enabled, bool $reportOnly): void
    {
        $csp             = new Csp();
        $csp->enabled    = $enabled;
        $csp->reportOnly = $reportOnly;

        Factories::injectMock('config', 'Csp', $csp);
    }

    public function testResponseHasCspReportOnlyHeader(): void
    {
        // 기본 config(enabled=true, reportOnly=true)면 Report-Only 헤더가 붙는다.
        $result = $this->call('GET', 'about');

        $policy = $result->response()->getHeaderLine('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
    }

    public function testCspHeaderAbsentWhenDisabled(): void
    {
        $this->injectCsp(enabled: false, reportOnly: true);

        $result = $this->call('GET', 'about');

        $this->assertFalse($result->response()->hasHeader('Content-Security-Policy-Report-Only'));
        $this->assertFalse($result->response()->hasHeader('Content-Security-Policy'));
        // 회귀 방지: CSP 를 꺼도 기존 보안 헤더는 그대로여야 한다.
        $result->assertHeader('X-Frame-Options', 'DENY');
    }

    public function testCspHeaderIsEnforceWhenReportOnlyOff(): void
    {
        $this->injectCsp(enabled: true, reportOnly: false);

        $result = $this->call('GET', 'about');

        $this->assertTrue($result->response()->hasHeader('Content-Security-Policy'));
        $this->assertFalse($result->response()->hasHeader('Content-Security-Policy-Report-Only'));
    }
}
