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
        // 페이저 링크는 href 속성 안에 있으므로 assertSee(텍스트 노드 검색)가 아니라
        // 본문 문자열로 확인한다. 한글은 퍼센트 인코딩되므로 직접 인코딩해 비교한다.
        $body = $result->getBody();
        $this->assertStringContainsString('status=draft', $body);
        $this->assertStringContainsString('q=' . rawurlencode('초안'), $body);
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
}
