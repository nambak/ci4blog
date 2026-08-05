<?php

namespace Tests\Feature;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * 에러 화면(404·500)에 대한 Feature 테스트. (#114)
 *
 * CI4 는 에러 뷰를 컨트롤러 없이 평범한 include 로 렌더한다
 * (BaseExceptionHandler::render(), vendor/.../Debug/BaseExceptionHandler.php:264-272).
 * 그래서 FeatureTestTrait::call() 로는 404 본문을 볼 수 없다 — 실측하면
 * TestResponse 대신 PageNotFoundException 이 그대로 던져진다.
 *
 * 따라서 화면 검증은 CI4 와 같은 경로를 재현해서 한다: 뷰 파일을 include 하고
 * 출력 버퍼를 취한다. 상태코드 자체는 라우팅 계약(testMissingPostThrowsPageNotFound)
 * 으로 따로 고정한다.
 */
final class ErrorPageTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    // 공용 헤더가 auth() 를 호출하므로 Shield/Settings 테이블이 필요하다.
    protected $namespace = null;
    protected $refresh   = true;

    /**
     * CI4 예외 핸들러와 같은 방식으로 에러 뷰를 렌더한다.
     *
     * $vars 는 BaseExceptionHandler::collectVars() 가 extract 로 넣어 주는 것과
     * 같은 변수들이다(message, code, title, type, file, line, trace).
     *
     * 반환 전에 html_entity_decode 를 거치는 이유가 있다 — CI 의 ubuntu 환경에서
     * 한글이 숫자 엔티티로 나오는 경우가 있어, 디코드하지 않으면 한국어 문구
     * 검사가 환경에 따라 흔들린다.
     */
    private function renderErrorView(string $file, array $vars = []): string
    {
        extract($vars, EXTR_SKIP);

        ob_start();
        include APPPATH . 'Views/errors/html/' . $file;

        return html_entity_decode((string) ob_get_clean(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function render404(): string
    {
        return $this->renderErrorView('error_404.php', ['message' => 'Page Not Found', 'code' => 404]);
    }

    /** 라우팅 계약 — 없는 글은 404 예외로 끝난다. */
    public function testMissingPostThrowsPageNotFound(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->call('get', '/posts/no-such-slug-xyz');
    }

    public function testNotFoundPageIsInKorean(): void
    {
        $html = $this->render404();

        $this->assertStringContainsString('찾는 글이 없습니다', $html);
        $this->assertStringContainsString('lang="ko"', $html);
    }

    public function testNotFoundPageOffersSearch(): void
    {
        $html = $this->render404();

        // 검색폼이 실제로 있어야 한다(GET 이라 CSRF 토큰은 필요 없다).
        $this->assertStringContainsString('name="q"', $html);
        $this->assertStringContainsString('검색', $html);
    }

    public function testNotFoundPageInheritsSharedLayout(): void
    {
        $html = $this->render404();

        // layouts/default.php:29 · partials/header.php:7 · partials/footer.php:6
        $this->assertStringContainsString('page-main', $html);
        $this->assertStringContainsString('home-header', $html);
        $this->assertStringContainsString('home-footer', $html);
    }

    public function testNotFoundPageDropsFrameworkDefaultLook(): void
    {
        $html = $this->render404();

        // 음성 단언만 두면 페이지가 통째로 비어도 통과한다.
        // 먼저 화면이 실제로 그려졌음을 확인하고 나서 CI4 흔적이 없음을 본다.
        $this->assertStringContainsString('찾는 글이 없습니다', $html);
        $this->assertStringNotContainsString('dd4814', $html);
    }
}
