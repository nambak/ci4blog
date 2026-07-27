<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * 헬스체크(#112). 외부 업타임 모니터가 거는 공개 엔드포인트.
 *
 * 앱이 살아 있고 DB 에 연결되면 200, DB 장애면 503 을 준다. 모니터·로드밸런서가
 * 비200 을 unhealthy 로 인식하도록 — DB 가 죽었는데 200 을 주면 장애가 안 잡힌다.
 */
class Health extends BaseController
{
    public function index(): ResponseInterface
    {
        $up = $this->databaseIsUp();

        return $this->response
            ->setStatusCode($up ? 200 : 503)
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON([
                'status' => $up ? 'ok' : 'error',
                'db'     => $up ? 'ok' : 'down',
            ]);
    }

    /**
     * DB 연결·응답 확인. 실패는 모두 삼켜 down 으로 본다 — 헬스체크가 스스로
     * 500 을 내면 안 되기 때문이다. 테스트는 이 메서드를 오버라이드해 503 경로를 태운다.
     */
    protected function databaseIsUp(): bool
    {
        try {
            // DBDebug=false(운영 기본)면 query() 는 실패 시 예외가 아니라 false 를
            // 반환한다. 무조건 true 를 돌려주면 DB 장애 중에도 200 이 나가므로,
            // 반환값이 false 가 아닌지까지 확인한다.
            return db_connect()->query('SELECT 1') !== false;
        } catch (Throwable) {
            return false;
        }
    }
}
