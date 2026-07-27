<?php

use App\Controllers\Health;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/**
 * /health 의 DB 장애 경로. db_connect 를 실제로 깨뜨리기 어려우므로,
 * DB 확인 seam(databaseIsUp)을 false 로 오버라이드해 503 분기를 태운다.
 * (SecurityHeadersHstsTest 가 필터를 직접 인스턴스화하는 것과 같은 결.)
 *
 * @internal
 */
final class HealthDbDownTest extends CIUnitTestCase
{
    public function testReturns503WhenDbDown(): void
    {
        $health = new class extends Health {
            protected function databaseIsUp(): bool
            {
                return false;
            }
        };
        $health->initController(Services::request(), new Response(config('App')), Services::logger());

        $result = $health->index();

        $this->assertSame(503, $result->getStatusCode());

        $json = json_decode((string) $result->getBody(), true);
        $this->assertSame('error', $json['status']);
        $this->assertSame('down', $json['db']);
    }
}
