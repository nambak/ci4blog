<?php

namespace Tests\Feature;

use App\Entities\Post;
use CodeIgniter\Shield\Entities\User;
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
}
