<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * 요청 속도 제한 설정(#111).
 *
 * 임계값을 여기 한곳에 모아 둔다. 정책이 코드 여기저기 흩어지지 않고,
 * 테스트는 낮은 임계값을 주입해 실제 횟수만큼 호출하지 않아도 된다.
 */
class RateLimit extends BaseConfig
{
    /**
     * 전역 스위치.
     *
     * 운영에서 예상 못 한 차단이 생기면 .env 의 `ratelimit.enabled = false`
     * 로 재배포 없이 끌 수 있다.
     */
    public bool $enabled = true;

    /**
     * 액션별 제한. capacity 회분의 토큰이 seconds 에 걸쳐 재충전되는
     * 토큰 버킷이다.
     *
     * .env 로 개별 조정할 수 있다: `ratelimit.limits.login.capacity = 3`
     * (.env 값은 문자열로 들어오므로 필터에서 int 로 캐스팅한다.)
     *
     * @var array<string, array{capacity: int, seconds: int}>
     */
    public array $limits = [
        'login'   => ['capacity' => 5,  'seconds' => 5 * MINUTE],
        'comment' => ['capacity' => 5,  'seconds' => 5 * MINUTE],
        'report'  => ['capacity' => 10, 'seconds' => HOUR],
        'like'    => ['capacity' => 30, 'seconds' => MINUTE],
    ];
}
