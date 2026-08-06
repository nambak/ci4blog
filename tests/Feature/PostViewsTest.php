<?php

namespace Tests\Feature;

use App\Entities\Post;
use App\Models\PostModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\WithCsrf;

/**
 * 글 조회수. (#114)
 *
 * 증가는 쿼리 빌더로 원자적으로 한다. 모델 update() 를 쓰면 views 가
 * $allowedFields 에 없어 무시될 뿐 아니라 $useTimestamps 가 updated_at 을
 * 덮어 sitemap 의 <lastmod> 까지 오염시킨다 — testViewingDoesNotTouchUpdatedAt
 * 이 그 계약을 고정한다.
 */
final class PostViewsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use WithCsrf;
    use AuthenticationTesting;

    protected $namespace = null;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startNewSession();
    }

    /** 새 방문자를 흉내 낸다 — 세션을 비우고 서비스를 다시 만든다. */
    private function startNewSession(): void
    {
        $_SESSION = [];
        \Config\Services::resetSingle('session');
        \Config\Services::resetSingle('auth');
    }

    private function makeUser(string $username = 'writer', string $email = 'writer@example.com'): User
    {
        $users = auth()->getProvider();
        $user  = new User(['username' => $username, 'email' => $email, 'password' => 'secret-password-123']);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    private function makePost(int $userId, array $overrides = []): Post
    {
        $posts = model(PostModel::class);
        $posts->insert(array_merge([
            'user_id' => $userId,
            'title'   => 'Views Test Post',
            'body'    => '본문',
            'status'  => Post::STATUS_PUBLISHED,
        ], $overrides));

        return $posts->find($posts->getInsertID());
    }

    private function viewsOf(int $postId): int
    {
        return (int) db_connect()->table('posts')->where('id', $postId)->get()->getRow()->views;
    }

    public function testFirstViewIncrementsTheCounter(): void
    {
        $post = $this->makePost((int) $this->makeUser()->id);

        $this->assertSame(0, $this->viewsOf((int) $post->id), '새 글은 0 에서 시작한다');

        $this->call('get', 'posts/' . $post->slug)->assertStatus(200);

        $this->assertSame(1, $this->viewsOf((int) $post->id));
    }

    /**
     * 🔴 같은 세션에서 새로고침해도 오르지 않는다.
     *
     * ⚠️ FeatureTestTrait 는 각 call() 을 **독립 요청**으로 다뤄, 앞 호출이 세션에
     * 남긴 값을 다음 호출의 컨트롤러가 보지 못한다(실측으로 확인했다 — 테스트 코드의
     * session() 과 컨트롤러 안의 session() 이 다른 인스턴스다).
     * 실제 브라우저는 쿠키로 세션을 유지하므로 이것은 하네스의 특성이지 기능 결함이 아니다.
     *
     * 그래서 `withSession()` 으로 앞 요청의 세션을 명시적으로 넘겨 브라우저 흐름을
     * 흉내 낸다. 인자 없이 부르면 현재 $_SESSION 을 주입하고, 한 번 설정하면
     * 이후 call() 에도 유지된다.
     */
    public function testSecondViewInTheSameSessionDoesNotIncrement(): void
    {
        $post = $this->makePost((int) $this->makeUser()->id);

        $this->call('get', 'posts/' . $post->slug);

        // 같은 방문자가 새로고침한다 — 앞 요청이 남긴 세션을 그대로 들고 간다.
        $this->withSession()->call('get', 'posts/' . $post->slug);
        $this->call('get', 'posts/' . $post->slug);

        $this->assertSame(1, $this->viewsOf((int) $post->id), '세션당 한 번만 센다');
    }

    /** 새 방문자(빈 세션)면 다시 센다. */
    public function testNewSessionCountsAgain(): void
    {
        $post = $this->makePost((int) $this->makeUser()->id);

        $this->call('get', 'posts/' . $post->slug);

        // 빈 세션을 주입해 새 방문자를 만든다.
        $this->withSession([])->call('get', 'posts/' . $post->slug);

        $this->assertSame(2, $this->viewsOf((int) $post->id));
    }

    /**
     * 🔴 작성자가 자기 초안을 미리보기하는 것은 조회가 아니다.
     *
     * post_viewable() 은 통과하지만(작성자에게는 보인다) isPublished() 가 아니므로
     * 세지 않는다.
     */
    public function testPreviewingAnUnpublishedPostDoesNotCount(): void
    {
        $user = $this->makeUser();
        $post = $this->makePost((int) $user->id, ['status' => Post::STATUS_DRAFT]);

        $this->actingAs($user)->call('get', 'posts/' . $post->slug)->assertStatus(200);

        $this->assertSame(0, $this->viewsOf((int) $post->id));
    }

    /**
     * 🔴 조회가 updated_at 을 건드리면 안 된다.
     *
     * 모델 update() 를 쓰면 $useTimestamps 가 updated_at 을 덮는데, 그러면
     * sitemap 의 <lastmod> 와 RSS 가 통째로 오염된다(publishedForSitemap 이
     * updated_at DESC 첫 행을 사이트 전체의 최신 시각으로 쓴다, #124).
     *
     * ⚠️ 앞뒤 값을 비교하는 방식은 쓰지 않는다 — 조회 전후가 같은 초면 덮여도
     * 값이 같아 통과한다(#136 에서 겪은 시간 의존 위양성). 과거 앵커를 심는다.
     */
    public function testViewingDoesNotTouchUpdatedAt(): void
    {
        $post   = $this->makePost((int) $this->makeUser()->id);
        $anchor = '2020-01-01 00:00:00';

        db_connect()->table('posts')->where('id', $post->id)->update(['updated_at' => $anchor]);

        $this->call('get', 'posts/' . $post->slug)->assertStatus(200);

        $row = db_connect()->table('posts')->where('id', $post->id)->get()->getRow();

        $this->assertSame(1, (int) $row->views, '조회수는 올라야 한다');
        $this->assertSame($anchor, $row->updated_at, 'updated_at 이 덮였다 — sitemap lastmod 가 오염된다');
    }

    private function makeAdmin(): User
    {
        $admin = $this->makeUser('admin', 'admin@example.com');
        $admin->addGroup('admin');

        return $admin;
    }

    public function testAdminPostsTableShowsTheViewCount(): void
    {
        $author = $this->makeUser();
        $post   = $this->makePost((int) $author->id);

        // 조회수를 눈에 띄는 값으로 만들어 둔다(0 은 다른 숫자와 헷갈린다).
        db_connect()->table('posts')->where('id', $post->id)->update(['views' => 4242]);

        $html = html_entity_decode(
            $this->actingAs($this->makeAdmin())->call('get', 'admin/posts')->getBody(),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $this->assertStringContainsString('Views Test Post', $html, '글이 목록에 나와야 한다');
        // number_format 으로 천 단위를 구분해 보여 준다 — 표시 형식까지 계약이다.
        $this->assertStringContainsString('4,242', $html);
    }

    /**
     * 🔴 글 수정 폼으로 조회수를 조작할 수 없어야 한다.
     *
     * 정적으로 $allowedFields 를 뒤지는 대신 실제로 폼을 제출한다 —
     * "목록에 없다"보다 "조작이 통하지 않는다"가 계약이다.
     */
    public function testViewCountCannotBeMassAssignedThroughTheEditForm(): void
    {
        $author = $this->makeUser();
        $post   = $this->makePost((int) $author->id);

        db_connect()->table('posts')->where('id', $post->id)->update(['views' => 10]);

        $this->actingAs($author)->post('posts/' . $post->id, [
            'title' => '고친 제목',
            'body'  => '고친 본문',
            'views' => 9999,
        ]);

        $this->assertSame(10, $this->viewsOf((int) $post->id), 'views 가 대량 할당으로 덮였다');
    }
}
