<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 토큰으로 인증된 사용자가 관리자 그룹인지 확인한다. tokens 필터 뒤에 건다.
 *
 * Shield 의 group 필터를 쓸 수 없어서 만들었다. 그 필터는 AbstractAuthFilter 를
 * 상속하는데, 거기서 `auth()` 를 부른다 — 인자가 없으면 기본 인증자(session)다.
 * 토큰으로 들어온 요청은 세션이 없으므로 로그인하지 않은 것으로 판정되고,
 * 403 JSON 이 아니라 **로그인 페이지로 리다이렉트**된다. 스크립트 입장에서는
 * 권한 오류가 HTML 302 로 돌아오는 셈이라 원인을 짚기가 어렵다.
 *
 * 그래서 인증자를 'tokens' 로 못 박고, 실패를 JSON 403 으로 돌려준다.
 */
class ApiAdmin implements FilterInterface
{
    /** 인자를 안 주면 이 그룹들을 본다. */
    private const DEFAULT_GROUPS = ['admin', 'superadmin'];

    public function before(RequestInterface $request, $arguments = null)
    {
        $groups = empty($arguments) ? self::DEFAULT_GROUPS : $arguments;

        $user = auth('tokens')->user();

        // 인증 자체는 앞의 tokens 필터가 끝냈다. 여기까지 왔는데 사용자가 없다면
        // 필터 순서가 틀어진 것이므로, 통과시키지 않고 그 사실을 드러낸다.
        if ($user === null) {
            return service('response')
                ->setStatusCode(Response::HTTP_UNAUTHORIZED)
                ->setJSON(['messages' => ['error' => '인증이 필요합니다.']]);
        }

        if (! $user->inGroup(...$groups)) {
            return service('response')
                ->setStatusCode(Response::HTTP_FORBIDDEN)
                ->setJSON(['messages' => [
                    'error' => '이 토큰에는 발행 권한이 없습니다. (' . implode(', ', $groups) . ' 그룹 필요)',
                ]]);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
