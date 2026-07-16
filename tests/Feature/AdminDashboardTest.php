<?php

namespace Tests\Feature;

use App\Models\CategoryModel;
use App\Models\CommentModel;
use App\Models\PostModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Shield\Test\AuthenticationTesting;

/**
 * 관리자 대시보드(/admin)에 대한 Feature 테스트.
 *
 * - 비로그인 사용자는 접근할 수 없다(로그인으로 리다이렉트).
 * - 일반 user 그룹은 접근할 수 없다(리다이렉트).
 * - admin 그룹은 200으로 대시보드를 본다.
 */
final class AdminDashboardTest extends CIUnitTestCase
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

        $user = new User([
            'username' => $username,
            'email'    => $email,
            'password' => 'secret-password-123',
        ]);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    private function makeAdmin(): User
    {
        $admin = $this->makeUser('admin', 'admin@example.com');
        $admin->addGroup('admin');

        return $admin;
    }

    public function testGuestCannotAccessDashboard(): void
    {
        $result = $this->call('GET', 'admin');

        $result->assertRedirect();
    }

    public function testNormalUserCannotAccessDashboard(): void
    {
        $user = $this->makeUser('member', 'member@example.com');

        $result = $this->actingAs($user)->call('GET', 'admin');

        $result->assertRedirect();
    }

    public function testAdminSeesDashboard(): void
    {
        $admin = $this->makeAdmin();

        $result = $this->actingAs($admin)->call('GET', 'admin');

        $result->assertStatus(200);
        $result->assertSee('대시보드');
    }

    public function testDashboardShowsKpiCounts(): void
    {
        $admin = $this->makeAdmin();

        $posts      = model(PostModel::class);
        $comments   = model(CommentModel::class);
        $categories = model(CategoryModel::class);

        // 글 3개(모두 이번 달), 카테고리 2개, 댓글 4개를 심는다.
        $categories->insert(['name' => '일상', 'slug' => 'daily']);
        $categories->insert(['name' => '개발', 'slug' => 'dev']);

        $postIds = [];
        foreach (['가', '나', '다'] as $t) {
            $posts->insert(['user_id' => $admin->id, 'title' => "글{$t}", 'body' => '본문']);
            $postIds[] = $posts->getInsertID();
        }
        foreach (range(1, 4) as $n) {
            $comments->insert(['post_id' => $postIds[0], 'user_id' => $admin->id, 'body' => "댓글{$n}"]);
        }

        $result = $this->actingAs($admin)->call('GET', 'admin');

        $result->assertStatus(200);
        $result->assertSee('3', '#kpi-posts');       // 전체 글
        $result->assertSee('4', '#kpi-comments');    // 전체 댓글
        $result->assertSee('2', '#kpi-categories');  // 카테고리
        $result->assertSee('3', '#kpi-month');       // 이번 달 새 글
    }

    public function testDashboardShowsRecentAndDistribution(): void
    {
        $admin = $this->makeAdmin();

        $posts      = model(PostModel::class);
        $comments   = model(CommentModel::class);
        $categories = model(CategoryModel::class);

        $categories->insert(['name' => '여행기록', 'slug' => 'travel']);
        $catId = $categories->getInsertID();

        $posts->insert([
            'user_id'     => $admin->id,
            'category_id' => $catId,
            'title'       => '유일무이한제목ABC',
            'body'        => '본문',
        ]);
        $postId = $posts->getInsertID();

        $comments->insert([
            'post_id' => $postId,
            'user_id' => $admin->id,
            'body'    => '독특한댓글내용XYZ',
        ]);

        $result = $this->actingAs($admin)->call('GET', 'admin');

        $result->assertStatus(200);
        $result->assertSee('유일무이한제목ABC');   // 최근 글 패널
        $result->assertSee('독특한댓글내용XYZ');   // 최근 댓글 패널
        $result->assertSee('여행기록');            // 카테고리 분포 패널
    }

    public function testAdminSeesAdminNavLink(): void
    {
        $admin = $this->makeAdmin();

        $result = $this->actingAs($admin)->call('GET', '/');

        $result->assertSee('관리자');
    }

    public function testNormalUserDoesNotSeeAdminNavLink(): void
    {
        $user = $this->makeUser('member', 'member@example.com');

        $result = $this->actingAs($user)->call('GET', '/');

        $result->assertDontSee('관리자');
    }

    public function testRecentPostsCardLinksToPostAdmin(): void
    {
        $admin = $this->makeAdmin();

        $result = $this->actingAs($admin)->call('GET', 'admin');

        // 게시글 관리로 가는 길은 '최근 글' 카드 헤드의 card-link 다
        // (카테고리 분포 카드가 '카테고리 관리 →'를 두는 것과 같은 자리).
        $result->assertStatus(200);
        $result->assertSee('게시글 관리 →');
        $this->assertStringContainsString(
            'class="card-link" href="' . site_url('admin/posts') . '"',
            $result->getBody()
        );
    }

    public function testDashboardLinksToCommentAdmin(): void
    {
        $admin = $this->makeAdmin();

        $result = $this->actingAs($admin)->call('GET', 'admin');

        $result->assertStatus(200);
        $result->assertSee('댓글 관리 →');
        $this->assertStringContainsString(
            'class="card-link" href="' . site_url('admin/comments') . '"',
            $result->getBody()
        );
    }

    public function testHeaderHasNoPostAdminNavLink(): void
    {
        $admin = $this->makeAdmin();

        // 헤더에는 '관리자' 하나만 둔다. 게시글 관리는 대시보드 안에서 들어간다.
        $result = $this->actingAs($admin)->call('GET', '/');

        $result->assertStatus(200);
        $this->assertStringNotContainsString(
            'class="nav-link" href="' . site_url('admin/posts') . '"',
            $result->getBody()
        );
    }
}
