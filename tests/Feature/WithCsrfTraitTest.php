<?php

namespace Tests\Feature;

use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\WithCsrf;

/**
 * 테스트 인프라인 WithCsrf 트레잇 자체에 대한 방지망.
 *
 * 이 트레잇은 12개 Feature 테스트의 POST 호출 전부가 의존하는 지점이라,
 * 조용히 망가지면 그 테스트들이 "통과하지만 아무것도 검증하지 않는" 상태가 된다.
 *
 * @see \Tests\Support\Traits\WithCsrf
 */
final class WithCsrfTraitTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use WithCsrf;

    protected $namespace = null;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        \Config\Services::resetSingle('session');
        \Config\Services::resetSingle('auth');
    }

    /**
     * 토큰을 주지 않으면 자동으로 실어준다.
     *
     * 이것이 트레잇의 존재 이유다 — 덕분에 기존 POST 호출부 81곳을 고치지 않아도 된다.
     * 비로그인이라 CSRF 를 통과한 뒤 session 필터에 막혀 리다이렉트된다.
     */
    public function testInjectsTokenWhenAbsent(): void
    {
        $result = $this->call('POST', 'posts/1/comments', ['body' => '자동 주입 댓글']);

        $result->assertRedirect();
    }

    /**
     * 테스트가 직접 넣은 토큰은 덮어쓰지 않는다(트레잇의 ??= 부분).
     *
     * 덮어쓴다면 위조 토큰 시나리오를 이 트레잇을 쓴 채로는 쓸 수 없고,
     * 더 나쁘게는 자동 주입이 테스트의 명시적 의도를 조용히 무력화하게 된다.
     */
    public function testDoesNotOverrideExplicitToken(): void
    {
        $this->expectException(SecurityException::class);

        $this->call('POST', 'posts/1/comments', [
            'body'       => '위조 토큰 댓글',
            csrf_token() => 'forged-token-value',
        ]);
    }

    /**
     * GET 은 손대지 않는다. CSRF 는 POST/PUT/DELETE/PATCH 만 보호하므로
     * GET 파라미터에 토큰을 끼워 넣으면 질의 문자열만 더럽힌다.
     */
    public function testDoesNotTouchGetRequests(): void
    {
        $result = $this->call('GET', 'posts');

        $result->assertOK();
        $this->assertArrayNotHasKey(csrf_token(), $_GET);
    }
}
