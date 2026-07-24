<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 응답에 보안 헤더를 부착하는 after 필터.
 *
 * 항상: X-Content-Type-Options(nosniff), Referrer-Policy, X-Frame-Options(DENY).
 * HTTPS 요청일 때만: Strict-Transport-Security(HSTS, 6개월·includeSubDomains, preload 없음).
 */
class SecurityHeaders implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // no-op: 이 필터는 응답 단계에서만 동작한다.
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->setHeader('X-Frame-Options', 'DENY');

        // HSTS 는 실제 HTTPS 요청에만 부착(dev=HTTP·CLI 제외).
        // preload 없음 — max-age=0 재전송으로 되돌릴 수 있게 유지.
        if ($request instanceof IncomingRequest && $request->isSecure()) {
            $response->setHeader('Strict-Transport-Security', 'max-age=15552000; includeSubDomains');
        }
    }
}
