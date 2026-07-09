<?php

namespace Tests\Feature;

use App\Entities\Post;
use App\Models\CategoryModel;
use App\Models\PostModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * 관리자 게시글 관리(/admin/posts) Feature 테스트.
 */
final class AdminPostsTest extends CIUnitTestCase
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

    private function insertPost(string $title, string $status = Post::STATUS_PUBLISHED, ?int $categoryId = null): int
    {
        $posts = model(PostModel::class);
        $posts->insert([
            'title'       => $title,
            'body'        => '본문',
            'status'      => $status,
            'category_id' => $categoryId,
        ]);

        return $posts->getInsertID();
    }

    public function testGuestCannotAccess(): void
    {
        $this->call('GET', 'admin/posts')->assertRedirect();
    }

    public function testNormalUserCannotAccess(): void
    {
        $user = $this->makeUser('member', 'member@example.com');
        $this->actingAs($user)->call('GET', 'admin/posts')->assertRedirect();
    }

    public function testAdminSeesAllStatuses(): void
    {
        $admin = $this->makeAdmin();
        $this->insertPost('공개된 글');
        $this->insertPost('초안 상태 글', Post::STATUS_DRAFT);
        $this->insertPost('숨긴 글', Post::STATUS_PRIVATE);

        $result = $this->actingAs($admin)->call('GET', 'admin/posts');

        $result->assertStatus(200);
        $result->assertSee('공개된 글');
        $result->assertSee('초안 상태 글');
        $result->assertSee('숨긴 글');
        $result->assertSee('게시글 관리', 'h1');
    }

    public function testStatusTabFiltersRows(): void
    {
        $admin = $this->makeAdmin();
        $this->insertPost('공개된 글');
        $this->insertPost('초안 상태 글', Post::STATUS_DRAFT);

        $result = $this->actingAs($admin)->call('GET', 'admin/posts?status=draft');

        $result->assertStatus(200);
        $result->assertSee('초안 상태 글');
        $result->assertDontSee('공개된 글');
    }

    public function testUnknownStatusFallsBackToAll(): void
    {
        $admin = $this->makeAdmin();
        $this->insertPost('공개된 글');
        $this->insertPost('초안 상태 글', Post::STATUS_DRAFT);

        $result = $this->actingAs($admin)->call('GET', 'admin/posts?status=archived');

        $result->assertStatus(200);
        $result->assertSee('공개된 글');
        $result->assertSee('초안 상태 글');
    }

    public function testSearchFiltersByTitleOnly(): void
    {
        $admin = $this->makeAdmin();
        $this->insertPost('찾을 제목');
        $this->insertPost('다른 제목');

        $result = $this->actingAs($admin)->call('GET', 'admin/posts?q=찾을');

        $result->assertStatus(200);
        $result->assertSee('찾을 제목');
        $result->assertDontSee('다른 제목');
    }

    public function testShowsCategoryNameAndCommentCount(): void
    {
        $admin = $this->makeAdmin();
        $categories = model(CategoryModel::class);
        $categories->insert(['name' => '여행기록']);
        $catId = $categories->getInsertID();

        $postId = $this->insertPost('댓글 달린 글', Post::STATUS_PUBLISHED, $catId);
        db_connect()->table('comments')->insert([
            'post_id'    => $postId,
            'user_id'    => $admin->id,
            'body'       => '댓글 본문',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $result = $this->actingAs($admin)->call('GET', 'admin/posts');

        $result->assertStatus(200);
        $result->assertSee('여행기록');
        // 댓글 수 1 이 행에 노출된다.
        $this->assertStringContainsString('댓글 1', $result->getBody());
    }

    public function testPaginationKeepsStatusAndSearch(): void
    {
        $admin = $this->makeAdmin();
        // PER_PAGE(20)를 넘겨 2페이지가 생기도록 21건을 만든다.
        for ($i = 1; $i <= 21; $i++) {
            $this->insertPost("초안 {$i}", Post::STATUS_DRAFT);
        }

        $result = $this->actingAs($admin)->call('GET', 'admin/posts?status=draft&q=초안');

        $result->assertStatus(200);

        // 탭 바(<a class="tab">)도 $tabUrl() 이 만든 링크 안에 status=draft·q=초안 을
        // 그대로 담고 있어서, 본문 전체에서 부분 문자열만 찾으면 페이저가 완전히
        // 고장 나도(예: only() 가 지운 것과 무관한 키만 남기도록 바뀌어도) 이 테스트는
        // 계속 통과해 버린다. 그래서 페이저 자신이 렌더링하는
        // app/Views/partials/pager.php 의 <nav class="pager">…</nav> 조각만 잘라내어
        // 그 안에서만 검증한다.
        $body = $result->getBody();
        $this->assertMatchesRegularExpression('/<nav class="pager"[^>]*>.*<\/nav>/s', $body);
        preg_match('/<nav class="pager"[^>]*>.*<\/nav>/s', $body, $navMatch);
        $pagerHtml = $navMatch[0];

        // 페이저가 만든 링크 중 2페이지로 가는 것의 href 하나 안에
        // page=2·status=draft·검색어가 모두 함께 들어 있어야 한다.
        // 탭 바는 page= 를 절대 넣지 않으므로, 이 조합은 페이저만 만들 수 있다.
        $this->assertMatchesRegularExpression('/href="[^"]*page=2[^"]*"/', $pagerHtml);
        preg_match('/href="([^"]*page=2[^"]*)"/', $pagerHtml, $hrefMatch);
        $pageTwoHref = $hrefMatch[1];

        $this->assertStringContainsString('status=draft', $pageTwoHref);
        $this->assertStringContainsString('q=' . rawurlencode('초안'), $pageTwoHref);
    }

    public function testStatCardsShowGlobalTotalsNotSearchScoped(): void
    {
        $admin = $this->makeAdmin();
        $this->insertPost('찾을 공개된 글');
        $this->insertPost('다른 초안', Post::STATUS_DRAFT);

        // 검색 중에도 통계 카드는 전체 기준(발행 1 · 임시 1)을 보여 준다.
        // 탭 카운트는 검색을 반영하므로(임시 0) 카드와 값이 갈린다.
        $result = $this->actingAs($admin)->call('GET', 'admin/posts?q=찾을');

        $result->assertStatus(200);
        // 대시보드 테스트와 같은 #kpi-* 선택자 관례를 쓴다.
        $result->assertSee('1', '#kpi-published');
        $result->assertSee('1', '#kpi-draft');
    }

    public function testGuestCannotPostBulk(): void
    {
        $id = $this->insertPost('공개된 글');

        $this->call('POST', 'admin/posts/bulk', ['action' => 'delete', 'ids' => [$id]])->assertRedirect();
        $this->seeInDatabase('posts', ['id' => $id]);
    }

    public function testNormalUserCannotPostBulk(): void
    {
        $user = $this->makeUser('member', 'member@example.com');
        $id   = $this->insertPost('공개된 글');

        $this->actingAs($user)->call('POST', 'admin/posts/bulk', ['action' => 'delete', 'ids' => [$id]])->assertRedirect();
        $this->seeInDatabase('posts', ['id' => $id]);
    }

    public function testBulkPublish(): void
    {
        $admin = $this->makeAdmin();
        $id    = $this->insertPost('초안 상태 글', Post::STATUS_DRAFT);

        $this->actingAs($admin)->call('POST', 'admin/posts/bulk', ['action' => 'publish', 'ids' => [$id]])->assertRedirect();
        $this->seeInDatabase('posts', ['id' => $id, 'status' => 'published']);
    }

    public function testBulkDraft(): void
    {
        $admin = $this->makeAdmin();
        $id    = $this->insertPost('공개된 글');

        $this->actingAs($admin)->call('POST', 'admin/posts/bulk', ['action' => 'draft', 'ids' => [$id]])->assertRedirect();
        $this->seeInDatabase('posts', ['id' => $id, 'status' => 'draft']);
    }

    public function testBulkPrivate(): void
    {
        $admin = $this->makeAdmin();
        $id    = $this->insertPost('공개된 글');

        $this->actingAs($admin)->call('POST', 'admin/posts/bulk', ['action' => 'private', 'ids' => [$id]])->assertRedirect();
        $this->seeInDatabase('posts', ['id' => $id, 'status' => 'private']);
    }

    public function testBulkMoveToCategory(): void
    {
        $admin      = $this->makeAdmin();
        $categories = model(CategoryModel::class);
        $categories->insert(['name' => '옮길분류']);
        $catId = $categories->getInsertID();

        $id = $this->insertPost('옮길 글');

        $this->actingAs($admin)->call('POST', 'admin/posts/bulk', [
            'action'      => 'move',
            'ids'         => [$id],
            'category_id' => (string) $catId,
        ])->assertRedirect();

        $this->seeInDatabase('posts', ['id' => $id, 'category_id' => $catId]);
    }

    public function testBulkMoveToUncategorized(): void
    {
        $admin      = $this->makeAdmin();
        $categories = model(CategoryModel::class);
        $categories->insert(['name' => '기존분류']);
        $catId = $categories->getInsertID();

        $id = $this->insertPost('옮길 글', Post::STATUS_PUBLISHED, $catId);

        $this->actingAs($admin)->call('POST', 'admin/posts/bulk', [
            'action'      => 'move',
            'ids'         => [$id],
            'category_id' => '',
        ])->assertRedirect();

        $this->assertNull(model(PostModel::class)->find($id)->category_id);
    }

    public function testBulkDelete(): void
    {
        $admin = $this->makeAdmin();
        $keep  = $this->insertPost('남길 글');
        $drop1 = $this->insertPost('지울 글 1');
        $drop2 = $this->insertPost('지울 글 2');

        $this->actingAs($admin)->call('POST', 'admin/posts/bulk', [
            'action' => 'delete',
            'ids'    => [$drop1, $drop2],
        ])->assertRedirect();

        $this->dontSeeInDatabase('posts', ['id' => $drop1]);
        $this->dontSeeInDatabase('posts', ['id' => $drop2]);
        $this->seeInDatabase('posts', ['id' => $keep]);
    }

    public function testBulkRejectsEmptyIds(): void
    {
        $admin = $this->makeAdmin();
        $id    = $this->insertPost('공개된 글');

        $this->actingAs($admin)->call('POST', 'admin/posts/bulk', ['action' => 'delete'])->assertRedirect();

        $this->seeInDatabase('posts', ['id' => $id]);
        $this->assertSame(['선택된 글이 없습니다.'], session('errors'));
    }

    public function testBulkRejectsUnknownAction(): void
    {
        $admin = $this->makeAdmin();
        $id    = $this->insertPost('공개된 글');

        $this->actingAs($admin)->call('POST', 'admin/posts/bulk', ['action' => 'archive', 'ids' => [$id]])->assertRedirect();

        $this->seeInDatabase('posts', ['id' => $id, 'status' => 'published']);
        $this->assertSame(['알 수 없는 작업입니다.'], session('errors'));
    }

    public function testBulkRejectsUnknownCategory(): void
    {
        $admin = $this->makeAdmin();
        $id    = $this->insertPost('옮길 글');

        $this->actingAs($admin)->call('POST', 'admin/posts/bulk', [
            'action'      => 'move',
            'ids'         => [$id],
            'category_id' => '9999',
        ])->assertRedirect();

        // 모델의 is_not_unique[categories.id] 검증이 막는다.
        $this->assertNull(model(PostModel::class)->find($id)->category_id);
    }
}
