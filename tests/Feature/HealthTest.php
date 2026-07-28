<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * /health 정상 경로. DB 가 살아 있으면 200 + {status:ok, db:ok}.
 *
 * DB 장애(503) 경로는 db_connect 를 강제로 실패시켜야 하므로 seam 을
 * 오버라이드하는 단위 테스트(tests/unit/HealthDbDownTest.php)가 맡는다.
 *
 * @internal
 */
final class HealthTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    public function testReturns200WithOkStatusWhenDbUp(): void
    {
        $result = $this->call('GET', 'health');

        $result->assertStatus(200);

        $json = json_decode($result->getJSON(), true);
        $this->assertSame('ok', $json['status']);
        $this->assertSame('ok', $json['db']);
    }

    public function testIsPubliclyAccessible(): void
    {
        // 비로그인으로 200 이어야 한다(session 필터 밖 = 로그인 리다이렉트 없음).
        $result = $this->call('GET', 'health');

        $result->assertStatus(200);
        $this->assertFalse($result->isRedirect(), '/health 는 로그인 리다이렉트 대상이 아니어야 한다.');
    }

    public function testSendsNoStoreCacheHeader(): void
    {
        $result = $this->call('GET', 'health');

        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
    }
}
