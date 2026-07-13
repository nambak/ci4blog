<?php

namespace Tests\Feature;

use App\Entities\Comment;
use App\Entities\Post;
use App\Models\CommentModel;
use App\Models\PostModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;

/**
 * 관리자 댓글 관리(/admin/comments) Feature 테스트.
 */
final class AdminCommentsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
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

    /**
     * 응답 본문을 한글이 그대로 담긴 문자열로 돌려준다.
     *
     * CI(ubuntu)의 libxml 은 비 ASCII 를 숫자 엔티티(&#45796;)로 인코딩해 돌려주어,
     * 한글을 직접 찾는 단언이 CI 에서만 깨진다(AdminPostsTest 에 같은 헬퍼가 있다).
     */
    private function decodedBody(TestResponse $result): string
    {
        return html_entity_decode($result->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function makeUser(string $username, string $email): User
    {
        $users = auth()->getProvider();
        $user  = new User(['username' => $username, 'email' => $email, 'password' => 'secret-password-123']);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    private function makeAdmin(): User
    {
        $admin = $this->makeUser('admin', 'admin@example.com');
        $admin->addGroup('admin');

        return $admin;
    }

    private function makePost(int $userId, string $title = '글 제목'): Post
    {
        $posts = model(PostModel::class);
        $posts->insert([
            'user_id' => $userId,
            'title'   => $title,
            'body'    => '본문',
            'status'  => Post::STATUS_PUBLISHED,
        ]);

        return $posts->find($posts->getInsertID());
    }

    private function insertComment(int $postId, int $userId, string $body, array $overrides = []): int
    {
        $model = model(CommentModel::class);
        $model->insert(array_merge([
            'post_id' => $postId,
            'user_id' => $userId,
            'body'    => $body,
        ], $overrides));

        return $model->getInsertID();
    }

    public function testGuestCannotAccess(): void
    {
        $this->call('GET', 'admin/comments')->assertRedirect();
    }

    public function testNormalUserCannotAccess(): void
    {
        $user = $this->makeUser('normal', 'normal@example.com');

        $this->actingAs($user)->call('GET', 'admin/comments')->assertRedirect();
    }

    public function testSuperadminCanAccess(): void
    {
        $super = $this->makeUser('super', 'super@example.com');
        $super->addGroup('superadmin');

        $this->actingAs($super)->call('GET', 'admin/comments')->assertStatus(200);
    }

    public function testAdminSeesCommentWithPostTitleAndAuthor(): void
    {
        $admin = $this->makeAdmin();
        $post  = $this->makePost($admin->id, '댓글이 달린 글');
        $this->insertComment($post->id, $admin->id, '어떤 댓글 본문');

        $result = $this->actingAs($admin)->call('GET', 'admin/comments');

        $result->assertStatus(200);
        $result->assertSee('어떤 댓글 본문');
        $result->assertSee('댓글이 달린 글');
        $result->assertSee('admin');
    }

    public function testRepliesAreNotTopLevelRowsButShownUnderParent(): void
    {
        $admin    = $this->makeAdmin();
        $post     = $this->makePost($admin->id);
        $parentId = $this->insertComment($post->id, $admin->id, '부모 댓글');
        $this->insertComment($post->id, $admin->id, '관리자 답글', ['parent_id' => $parentId]);

        $result = $this->actingAs($admin)->call('GET', 'admin/comments');

        $result->assertStatus(200);
        $result->assertSee('부모 댓글');
        // 답글은 부모 행 안의 미리보기로 보인다.
        $result->assertSee('관리자 답글');
        // 행은 하나뿐이다(답글이 별도 행으로 서지 않는다).
        $this->assertSame(1, substr_count($this->decodedBody($result), 'class="ct-row"'));

        // '전체' 탭 카운트도 행 수와 같이 최상위 1건이어야 한다(답글까지 세면 2가 되어 어긋난다).
        // 통계 카드가 같은 페이지에 있어 본문 전체에서 숫자를 찾으면 위양성이 나므로 탭 바만 잘라낸다.
        preg_match('/<div class="posts-tabs">.*?<\/div>/s', $this->decodedBody($result), $tabsMatch);
        $this->assertNotEmpty($tabsMatch, '탭 바를 찾지 못했다.');

        preg_match('/status=all[^"]*"[^>]*>\s*전체\s*<span class="tab-count">(\d+)<\/span>/s', $tabsMatch[0], $countMatch);
        $this->assertNotEmpty($countMatch, "'전체' 탭의 카운트를 찾지 못했다.");
        $this->assertSame('1', $countMatch[1]);
    }

    public function testHiddenTabShowsOnlyHidden(): void
    {
        $admin = $this->makeAdmin();
        $post  = $this->makePost($admin->id);
        $this->insertComment($post->id, $admin->id, '보이는 댓글');
        $this->insertComment($post->id, $admin->id, '숨긴 댓글', ['status' => Comment::STATUS_HIDDEN]);

        $result = $this->actingAs($admin)->call('GET', 'admin/comments?status=hidden');

        $result->assertStatus(200);
        $result->assertSee('숨긴 댓글');
        $result->assertDontSee('보이는 댓글');
    }

    public function testUnknownStatusFallsBackToAll(): void
    {
        $admin = $this->makeAdmin();
        $post  = $this->makePost($admin->id);
        $this->insertComment($post->id, $admin->id, '보이는 댓글');
        $this->insertComment($post->id, $admin->id, '숨긴 댓글', ['status' => Comment::STATUS_HIDDEN]);

        $result = $this->actingAs($admin)->call('GET', 'admin/comments?status=spam');

        $result->assertStatus(200);
        $result->assertSee('보이는 댓글');
        $result->assertSee('숨긴 댓글');
    }

    public function testSearchByBody(): void
    {
        $admin = $this->makeAdmin();
        $post  = $this->makePost($admin->id);
        $this->insertComment($post->id, $admin->id, '찾을 본문');
        $this->insertComment($post->id, $admin->id, '다른 본문');

        $result = $this->actingAs($admin)->call('GET', 'admin/comments?q=찾을');

        $result->assertStatus(200);
        $result->assertSee('찾을 본문');
        $result->assertDontSee('다른 본문');
    }

    public function testSearchByAuthorName(): void
    {
        $admin  = $this->makeAdmin();
        $writer = $this->makeUser('haneul', 'haneul@example.com');
        $post   = $this->makePost($admin->id);
        $this->insertComment($post->id, $writer->id, '하늘이 쓴 댓글');
        $this->insertComment($post->id, $admin->id, '관리자가 쓴 댓글');

        $result = $this->actingAs($admin)->call('GET', 'admin/comments?q=haneul');

        $result->assertStatus(200);
        $result->assertSee('하늘이 쓴 댓글');
        $result->assertDontSee('관리자가 쓴 댓글');
    }

    public function testAllTabCountIsSearchScoped(): void
    {
        $admin = $this->makeAdmin();
        $post  = $this->makePost($admin->id);
        $this->insertComment($post->id, $admin->id, '찾을 본문');
        $this->insertComment($post->id, $admin->id, '다른 본문');

        $result = $this->actingAs($admin)->call('GET', 'admin/comments?q=찾을');

        $result->assertStatus(200);

        // 카드(전체 기준)와 탭 카운트(검색 기준)가 한 화면에 있으므로 탭 바만 잘라내 본다.
        preg_match('/<div class="posts-tabs">.*?<\/div>/s', $this->decodedBody($result), $tabsMatch);
        $this->assertNotEmpty($tabsMatch, '탭 바를 찾지 못했다.');

        preg_match('/status=all[^"]*"[^>]*>\s*전체\s*<span class="tab-count">(\d+)<\/span>/s', $tabsMatch[0], $countMatch);
        $this->assertNotEmpty($countMatch, "'전체' 탭의 카운트를 찾지 못했다.");
        $this->assertSame('1', $countMatch[1]);
    }

    public function testStatCardsShowGlobalTotalsNotSearchScoped(): void
    {
        $admin = $this->makeAdmin();
        $post  = $this->makePost($admin->id);
        $this->insertComment($post->id, $admin->id, '찾을 본문');
        $this->insertComment($post->id, $admin->id, '숨긴 본문', ['status' => Comment::STATUS_HIDDEN]);

        // 검색 중에도 카드는 전체 기준(전체 2 · 숨김 1)이다.
        $result = $this->actingAs($admin)->call('GET', 'admin/comments?q=찾을');

        $result->assertStatus(200);
        $result->assertSee('2', '#kpi-total');
        $result->assertSee('1', '#kpi-hidden');
    }

    public function testPaginationKeepsStatusAndSearch(): void
    {
        $admin = $this->makeAdmin();
        $post  = $this->makePost($admin->id);
        // PER_PAGE(20)를 넘겨 2페이지가 생기도록 21건.
        for ($i = 1; $i <= 21; $i++) {
            $this->insertComment($post->id, $admin->id, "숨긴 댓글 {$i}", ['status' => Comment::STATUS_HIDDEN]);
        }

        $result = $this->actingAs($admin)->call('GET', 'admin/comments?status=hidden&q=숨긴');

        $result->assertStatus(200);

        // 탭 바 링크도 status·q 를 담고 있으므로, 페이저 조각만 잘라내 그 안에서 검사한다.
        // (탭 바는 page= 를 절대 넣지 않으므로 이 조합은 페이저만 만들 수 있다.)
        $body = $this->decodedBody($result);
        $this->assertMatchesRegularExpression('/<nav class="pager"[^>]*>.*?<\/nav>/s', $body);
        preg_match('/<nav class="pager"[^>]*>.*?<\/nav>/s', $body, $navMatch);

        $this->assertMatchesRegularExpression('/href="[^"]*page=2[^"]*"/', $navMatch[0]);
        preg_match('/href="([^"]*page=2[^"]*)"/', $navMatch[0], $hrefMatch);

        $this->assertStringContainsString('status=hidden', $hrefMatch[1]);
        $this->assertStringContainsString('q=' . rawurlencode('숨긴'), $hrefMatch[1]);
    }
}
