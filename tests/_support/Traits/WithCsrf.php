<?php

namespace Tests\Support\Traits;

use CodeIgniter\Test\FeatureTestTrait;

/**
 * CSRF 필터가 전역으로 켜진 뒤(#73) POST 요청을 보내는 Feature 테스트용 트레잇.
 *
 * FeatureTestTrait 를 대신 use 하면 POST 호출에 CSRF 토큰이 자동으로 실린다.
 * 그래서 테스트 본문의 call('POST', ...) 은 손대지 않아도 된다.
 *
 * 실제 폼이 csrf_field() 로 토큰을 싣는 것과 같은 경로(POST 바디)를 쓰므로,
 * 필터를 우회하는 게 아니라 정상 요청을 흉내 내는 것이다. 필터는 그대로 돈다.
 *
 * 토큰이 이미 params 에 있으면 덮어쓰지 않는다(??=). 덕분에 위조 토큰을 넣는
 * 테스트도 이 트레잇을 쓴 채로 작성할 수 있다.
 *
 * @see \Tests\Feature\CsrfProtectionTest CSRF 방어 자체를 검증하는 테스트
 */
trait WithCsrf
{
    use FeatureTestTrait {
        call as protected callWithoutCsrf;
    }

    /**
     * @param array<string, mixed>|null $params
     */
    public function call(string $method, string $path, ?array $params = null)
    {
        if (strtoupper($method) === 'POST') {
            $params ??= [];
            $params[csrf_token()] ??= csrf_hash();
        }

        return $this->callWithoutCsrf($method, $path, $params);
    }
}
