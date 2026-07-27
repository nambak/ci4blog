<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Method;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\RateLimit;
use InvalidArgumentException;

/**
 * 요청 속도 제한(#111).
 *
 * 라우트에 `throttle:<액션>` 으로 붙인다. 액션 이름은 Config\RateLimit 의
 * limits 키와 같아야 한다.
 */
class Throttle implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     *
     * @return \CodeIgniter\HTTP\RedirectResponse|null
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        /** @var RateLimit $config */
        $config = config(RateLimit::class);

        if (! $config->enabled) {
            return null;
        }

        // 폼 제출만 센다. GET 으로 폼을 여는 것은 제한 대상이 아니다.
        // getMethod() 는 RequestInterface 가 아니라 OutgoingRequest 가 가진
        // 메서드라 instanceof 로 좁힌다(SecurityHeaders 의 isSecure() 와 같은 이유).
        if (! $request instanceof IncomingRequest || $request->getMethod() !== Method::POST) {
            return null;
        }

        $action = $arguments[0] ?? '';

        if (! isset($config->limits[$action])) {
            throw new InvalidArgumentException(
                'Throttle 필터에 알 수 없는 액션이 왔다: ' . ($action === '' ? '(인자 없음)' : $action)
            );
        }

        $limit     = $config->limits[$action];
        $throttler = service('throttler');

        if ($throttler->check($this->key($action, $request), (int) $limit['capacity'], (int) $limit['seconds'])) {
            return null;
        }

        $seconds = $throttler->getTokenTime();

        // 로그인 화면은 Shield 뷰가 session('error') 를, 앱 화면은 레이아웃이
        // session('message') 를 표시한다. 각 화면이 이미 가진 자리를 쓴다.
        return redirect()->back()->with(
            $action === 'login' ? 'error' : 'message',
            "요청이 너무 잦습니다. {$seconds}초 후 다시 시도해 주세요."
        );
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }

    /**
     * 로그인은 인증 전이라 IP 로 센다. 나머지는 session 필터가 로그인을
     * 보장하므로 사용자 ID 로 센다 — 공유 IP 뒤의 여러 사용자가 서로를
     * 막는 오탐을 피하기 위해서다.
     */
    private function key(string $action, IncomingRequest $request): string
    {
        // 캐시 키에는 콜론 등 예약 문자({}()/\@:)를 쓸 수 없다. 구분자는 점으로
        // 두고, IPv6(콜론 포함)까지 안전하도록 IP 는 해시해서 붙인다.
        if ($action !== 'login') {
            $userId = auth()->id();

            if ($userId !== null) {
                return "throttle.{$action}.user.{$userId}";
            }
        }

        return "throttle.{$action}.ip." . md5($request->getIPAddress());
    }
}
