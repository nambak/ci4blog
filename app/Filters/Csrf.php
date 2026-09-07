<?php

namespace App\Filters;

use CodeIgniter\Filters\CSRF as FrameworkCsrf;
use CodeIgniter\HTTP\Method;
use CodeIgniter\HTTP\RequestInterface;

/**
 * 읽기 요청에서는 CSRF 검사를 아예 건드리지 않는 필터. (#179)
 *
 * 기본 CSRF 필터는 전역 before 에 걸려 있어 라우팅보다 먼저 돌고, 매번
 * service('security') 를 만든다. csrfProtection 이 'session' 이면 Security
 * 생성자가 configureSession() 으로 세션을 시작하므로(Security.php:207,224)
 * 존재하지 않는 경로를 두드리는 404·400 요청까지 세션 파일을 하나씩 남긴다.
 * 운영에 37,463개가 쌓였다.
 *
 * 잃는 보호는 없다. Security::verify() 자체가 POST·PUT·DELETE·PATCH 만
 * 검사하고 나머지는 즉시 반환하므로(Security.php 의 verify), 읽기 요청에서
 * security 를 만드는 일은 세션만 만들 뿐 아무것도 검증하지 않았다.
 *
 * 폼을 그리는 GET 은 뷰의 csrf_field() 가 토큰을 만들면서 세션을 시작한다 —
 * 그건 필요한 동작이고 이 필터와 무관하다.
 */
class Csrf extends FrameworkCsrf
{
    /**
     * Security::verify() 가 실제로 검사하는 메서드. 이 목록이 프레임워크와
     * 어긋나면 검증이 조용히 빠지므로 같은 값을 쓴다.
     */
    private const VERIFIED_METHODS = [
        Method::POST,
        Method::PUT,
        Method::DELETE,
        Method::PATCH,
    ];

    public function before(RequestInterface $request, $arguments = null)
    {
        if (! in_array($request->getMethod(), self::VERIFIED_METHODS, true)) {
            return null;
        }

        return parent::before($request, $arguments);
    }
}
