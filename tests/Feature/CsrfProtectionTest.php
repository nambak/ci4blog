<?php

namespace Tests\Feature;

use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Filters as FiltersConfig;

/**
 * CSRF 보호가 실제로 동작하는지 검증하는 Feature 테스트.
 *
 * 다른 Feature 테스트들은 Tests\Support\Traits\WithCsrf 로 토큰을 자동 주입하지만,
 * 여기서는 방어 자체가 검증 대상이므로 FeatureTestTrait 를 직접 써서 토큰을 손으로 다룬다.
 *
 * 검증 대상 엔드포인트는 댓글 저장(POST)이다. CSRF 필터는 전역 before 필터라
 * 라우트 필터(session)보다 먼저 돌기 때문에, 비로그인 상태로도 CSRF 만 따로 확인할 수 있다.
 */
final class CsrfProtectionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        // 앞선 테스트의 세션(=CSRF 해시)과 로그인 상태가 새 나가지 않도록 비운다.
        // auth 까지 리셋해야 한다 — 로그인이 남아 있으면 session 필터를 통과해
        // 컨트롤러까지 들어가고, 픽스처가 없어 404 가 나면서 의도와 다른 실패가 된다.
        $_SESSION = [];
        \Config\Services::resetSingle('session');
        \Config\Services::resetSingle('auth');
    }

    /**
     * 토큰이 없는 POST 는 거부돼야 한다. 이것이 막히지 않으면
     * 로그인한 관리자가 공격자 페이지를 방문하는 것만으로 위조 요청이 성립한다.
     */
    public function testPostWithoutTokenIsRejected(): void
    {
        $this->expectException(SecurityException::class);

        $this->call('POST', 'posts/1/comments', ['body' => '토큰 없는 댓글']);
    }

    /**
     * 값이 틀린 토큰도 거부돼야 한다. 토큰 존재 여부만 보고 통과시키면
     * 공격자가 아무 값이나 넣어 우회할 수 있다.
     */
    public function testPostWithForgedTokenIsRejected(): void
    {
        $this->expectException(SecurityException::class);

        $this->call('POST', 'posts/1/comments', [
            'body'       => '위조 토큰 댓글',
            csrf_token() => 'forged-token-value',
        ]);
    }

    /**
     * 올바른 토큰이면 CSRF 필터를 통과한다.
     *
     * 비로그인이라 그다음 session 필터에 막혀 리다이렉트되는데, 그것으로 충분하다.
     * 요점은 SecurityException 이 나지 않는다는 것 — 즉 CSRF 단계는 넘었다는 뜻이다.
     */
    public function testPostWithValidTokenPassesCsrf(): void
    {
        $result = $this->call('POST', 'posts/1/comments', [
            'body'       => '정상 토큰 댓글',
            csrf_token() => csrf_hash(),
        ]);

        $result->assertRedirect();
    }

    /**
     * 설정 회귀 방지.
     *
     * 이 이슈(#73)는 "csrf 가 주석 처리된 줄 아무도 몰랐다"에서 출발했다. 위 세 테스트는
     * 필터가 꺼지면 함께 깨지지만, 그때 원인이 설정임을 바로 가리키도록 단언을 하나 둔다.
     */
    public function testCsrfFilterIsEnabledGlobally(): void
    {
        $globals = (new FiltersConfig())->globals;

        $this->assertContains(
            'csrf',
            $globals['before'],
            'app/Config/Filters.php 의 $globals[\'before\'] 에 csrf 가 있어야 한다.'
        );
    }
}
