<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

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
}
