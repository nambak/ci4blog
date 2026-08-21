<?php

namespace Tests\Feature;

use App\Entities\Post;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\WithCsrf;

/**
 * 작성 화면의 마크다운 미리보기(POST posts/preview). (#149)
 *
 * 이 엔드포인트의 계약은 하나다 — **미리보기가 실제 글과 같아야 한다.**
 * 다르면 볼 이유가 없다. 그래서 여기서 가장 중요한 검증은 응답 코드가 아니라
 * `Post::getBodyHtml()` 과 결과가 일치하는가다.
 *
 * 클라이언트 파서를 쓰지 않기로 한 이유이기도 하다. 표 wrapper·코드블록 접근성·
 * XSS 차단 규칙이 전부 그 엔티티 한 곳에 있고, 두 벌로 나뉘면 반드시 어긋난다.
 */
final class PostPreviewTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use WithCsrf;
    use AuthenticationTesting;

    protected $namespace = null;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        \Config\Services::resetSingle('session');
        \Config\Services::resetSingle('auth');
    }

    private function makeUser(): User
    {
        $users = auth()->getProvider();
        $user  = new User([
            'username' => 'writer',
            'email'    => 'writer@example.com',
            'password' => 'secret-password-123',
        ]);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    private function preview(string $body): \CodeIgniter\Test\TestResponse
    {
        return $this->actingAs($this->makeUser())->call('POST', 'posts/preview', ['body' => $body]);
    }

    /** 미리보기 결과는 실제 글의 본문 HTML 과 같다. */
    public function testPreviewMatchesRenderedPost(): void
    {
        $markdown = "# 제목\n\n**굵게** 그리고 [링크](https://example.com)";

        $result = $this->preview($markdown);

        $result->assertStatus(200);
        $this->assertSame(
            (new Post(['body' => $markdown]))->body_html,
            json_decode($result->response()->getBody(), true)['html'] ?? null,
            '미리보기가 실제 글과 다르다.'
        );
    }

    /**
     * 표도 실제 글처럼 접근성 컨테이너에 감싸여 나온다. (#150)
     *
     * 미리보기를 클라이언트 파서로 만들었다면 여기서 갈렸을 것이다.
     */
    public function testPreviewKeepsTableAccessibilityWrapper(): void
    {
        $html = json_decode(
            $this->preview("| 항목 | 값 |\n|---|---|\n| 제목 | 테스트 |")->response()->getBody(),
            true
        )['html'] ?? '';

        $this->assertMatchesRegularExpression(
            '/<div(?=[^>]*\bclass="table-scroll")(?=[^>]*\btabindex="0")[^>]*>\s*<table>/u',
            $html
        );
    }

    /** 원시 HTML 은 미리보기에서도 이스케이프된다. */
    public function testPreviewEscapesRawHtml(): void
    {
        $html = json_decode(
            $this->preview('안녕 <script>alert(1)</script>')->response()->getBody(),
            true
        )['html'] ?? '';

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    /** 비로그인은 호출할 수 없다. */
    public function testGuestCannotPreview(): void
    {
        $result = $this->call('POST', 'posts/preview', ['body' => '# 제목']);

        $result->assertRedirect();
    }

    /**
     * 미리보기 응답은 **새 CSRF 토큰을 함께 돌려준다.**
     *
     * 이 저장소는 tokenRandomize·regenerate 가 모두 켜져 있어서, 요청 한 번마다 토큰이
     * 바뀐다. 미리보기가 토큰을 소모하고 새 값을 주지 않으면, 그 뒤에 누르는 **저장이
     * CSRF 로 막힌다** — 글을 쓰다 미리보기를 한 번 봤다는 이유로 저장이 실패하는 셈이다.
     *
     * 화면 쪽 JS 는 이 값으로 폼의 hidden 필드를 갱신한다.
     */
    public function testPreviewReturnsFreshCsrfToken(): void
    {
        $result = $this->preview('# 제목');
        $json   = json_decode($result->response()->getBody(), true);

        $this->assertArrayHasKey('token', $json, '새 CSRF 토큰이 응답에 없다.');
        $this->assertNotSame('', (string) $json['token']);
    }

    /**
     * 작성·수정 화면에 탭 마크업이 실제로 들어간다.
     *
     * 엔드포인트만 만들고 화면에 붙이지 않으면 기능이 없는 것과 같다.
     * 두 화면이 같은 부분 뷰를 쓰므로 한쪽만 빠지는 일도 여기서 걸린다.
     */
    public function testBothFormsCarryThePreviewTab(): void
    {
        $user = $this->makeUser();

        $create = $this->actingAs($user)->call('GET', 'posts/new')->response()->getBody();
        $this->assertStringContainsString('data-editor-tab="preview"', $create, '작성 화면에 탭이 없다.');
        $this->assertStringContainsString('data-preview-url=', $create, '작성 화면에 미리보기 주소가 없다.');

        $posts = model(\App\Models\PostModel::class);
        $posts->insert(['user_id' => $user->id, 'title' => '수정할 글', 'body' => '본문', 'slug' => 'edit-me']);
        $edit = $this->actingAs($user)->call('GET', 'posts/' . $posts->getInsertID() . '/edit')->response()->getBody();

        $this->assertStringContainsString('data-editor-tab="preview"', $edit, '수정 화면에 탭이 없다.');
        $this->assertStringContainsString('data-preview-url=', $edit, '수정 화면에 미리보기 주소가 없다.');
    }

    /**
     * 에디터 JS 를 정적 경로로 싣는다.
     *
     * `site_url()` 은 indexPage 설정에 따라 `/index.php/...` 를 만든다. 정적 파일은 그
     * 경로로 서빙되지 않아 **404 가 되고, JS 가 실행되지 않아 탭이 hidden 인 채로 남는다**
     * — 화면에서는 "미리보기가 안 눌린다" 로 보인다. 실제로 그렇게 깨졌다.
     *
     * 마크업 존재만 확인하는 테스트로는 이걸 못 잡아서, 경로 형태를 직접 못박는다.
     * CSS 가 base_url() 을 쓰는 것과 같은 이유다.
     */
    public function testEditorScriptUsesStaticPath(): void
    {
        $html = $this->actingAs($this->makeUser())->call('GET', 'posts/new')->response()->getBody();

        $this->assertMatchesRegularExpression('/<script[^>]+src="[^"]*\/assets\/js\/editor\.js/', $html, '에디터 JS 를 싣지 않는다.');
        $this->assertStringNotContainsString('index.php/assets/', $html, 'index.php 가 붙은 경로로는 정적 파일이 서빙되지 않는다.');
        // JS 가 CSRF 토큰을 이름으로 집는다. 이 속성이 없으면 토큰을 못 찾아 미리보기가
        // 403 이 되고, 화면에서는 "미리보기가 안 뜬다" 로만 보인다.
        $this->assertStringContainsString('data-csrf-name="' . csrf_token() . '"', $html);
    }

    /**
     * 미리보기가 돌려준 토큰으로 **실제 저장이 된다.**
     *
     * 이게 이 기능의 진짜 계약이다. 앞의 testPreviewReturnsFreshCsrfToken 은 응답에 키가
     * 있는지만 봤을 뿐, 그 값이 **쓸 수 있는 토큰인지**는 보지 않았다 — 아무 문자열이나
     * 넣어도 통과한다. 여기서 저장까지 해 봐야 "미리보기를 본 뒤 글이 저장된다" 가 증명된다.
     *
     * WithCsrf 는 params 에 토큰이 이미 있으면 덮어쓰지 않으므로(??=), 받은 토큰을 그대로
     * 실어 보낼 수 있다.
     */
    public function testTokenFromPreviewCanBeUsedToSave(): void
    {
        $user = $this->makeUser();

        $token = json_decode(
            $this->actingAs($user)->call('POST', 'posts/preview', ['body' => '# 미리보기'])->response()->getBody(),
            true
        )['token'];

        $result = $this->actingAs($user)->call('POST', 'posts', [
            csrf_token() => $token,
            'title'      => '미리보기 뒤 저장',
            'body'       => '본문',
        ]);

        $result->assertRedirect();
        $this->seeInDatabase('posts', ['title' => '미리보기 뒤 저장']);
    }

    /**
     * 아무 문자열이나 토큰 자리에 넣으면 막힌다 — 위 테스트의 **대조군**이다.
     *
     * 이게 없으면 앞의 테스트가 "토큰이 무엇이든 저장된다"는 뜻일 수도 있다.
     * CSRF 필터는 리다이렉트가 아니라 SecurityException 을 던진다(CsrfProtectionTest 와 동일).
     */
    public function testBogusTokenCannotSave(): void
    {
        $this->expectException(SecurityException::class);

        $this->actingAs($this->makeUser())->call('POST', 'posts', [
            csrf_token() => 'not-a-real-token',
            'title'      => '위조 토큰 저장',
            'body'       => '본문',
        ]);
    }
}
