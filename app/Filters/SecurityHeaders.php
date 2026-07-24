<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 응답에 보안 헤더를 부착하는 after 필터.
 *
 * 항상: X-Content-Type-Options(nosniff), Referrer-Policy, X-Frame-Options(DENY).
 * (HSTS 는 Task 2 에서 HTTPS 요청에만 추가된다.)
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
    }
}
