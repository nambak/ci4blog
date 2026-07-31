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

    private function decodedBody(\CodeIgniter\Test\TestResponse $result): string
    {
        return html_entity_decode($result->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function makePost(int $userId): \App\Entities\Post
    {
        $posts = model(PostModel::class);
        $posts->insert(['user_id' => $userId, 'title' => '글', 'body' => '본문', 'status' => \App\Entities\Post::STATUS_PUBLISHED]);

        return $posts->find($posts->getInsertID());
    }

    /** created_at 은 allowedFields 밖이라 며칠 전 댓글은 DB 에 직접 박는다. */
    private function commentDaysAgo(int $postId, int $userId, int $daysAgo): void
    {
        $model = model(CommentModel::class);
        $model->insert(['post_id' => $postId, 'user_id' => $userId, 'body' => '댓글']);
        db_connect()->table('comments')->where('id', $model->getInsertID())
            ->update(['created_at' => date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"))]);
    }


    /**
     * 자식 행을 남긴 채 글만 지운다 — 운영에 실재하는 고아 상태를 재현한다.
     *
     * 운영 SQLite 는 FK 가 적용되지 않아 고아 댓글이 남는다(#110 이 db:prune 을
     * 만든 부채가 그것이다). 테스트 DB 는 foreignKeys=true 라 CASCADE 로 댓글까지
     * 지워지므로 제약을 잠시 꺼야 한다.
     *
     * finally 로 되돌리는 것이 핵심이다. 예외로 빠져나가면 PRAGMA 가 꺼진 채
     * 남아 **이후 모든 테스트의 데이터 정합성이 무너진다**(실제로 겪었다).
     */
    private function deletePostIgnoringForeignKeys(int $postId): void
    {
        $db = db_connect();
        $db->query('PRAGMA foreign_keys = OFF');

        try {
            $db->table('posts')->where('id', $postId)->delete();
        } finally {
            $db->query('PRAGMA foreign_keys = ON');
        }
    }

    public function testDashboardShowsCommentDeltaWhenThisWeekExceeds(): void
    {
        $admin = $this->makeAdmin();
        $post  = $this->makePost($admin->id);
        // 글을 2주 이전으로 늙혀 글 카드 증감을 0으로 만든다 → kpi-delta-up 은 오직 댓글에서만 나온다.
        db_connect()->table('posts')->where('id', $post->id)
            ->update(['created_at' => date('Y-m-d H:i:s', strtotime('-30 days'))]);
        $this->commentDaysAgo($post->id, $admin->id, 2);  // 이번 주
        $this->commentDaysAgo($post->id, $admin->id, 3);  // 이번 주
        $this->commentDaysAgo($post->id, $admin->id, 10); // 지난 주 → 댓글 delta +1

        $body = $this->decodedBody($this->actingAs($admin)->call('GET', 'admin'));

        // 증가 배지 + 접근성 문구가 렌더된다(댓글 카드 기준).
        $this->assertStringContainsString('kpi-delta-up', $body);
        $this->assertStringContainsString('지난주 대비 1 증가', $body);
        $this->assertStringNotContainsString('kpi-delta-down', $body);
    }

    public function testDashboardShowsFlatDeltaWhenNoRecentActivity(): void
    {
        $admin = $this->makeAdmin();

        $body = $this->decodedBody($this->actingAs($admin)->call('GET', 'admin'));

        // 최근 2주 활동이 없으면 변화 없음(–) 배지.
        $this->assertStringContainsString('kpi-delta-flat', $body);
        $this->assertStringContainsString('지난주 대비 변화 없음', $body);
        $this->assertStringNotContainsString('kpi-delta-up', $body);
    }

    /**
     * 원글이 사라진 댓글이 있어도 대시보드가 뜬다. (#127)
     *
     * 최근 댓글 쿼리가 posts 를 LEFT JOIN 하므로 글이 없으면 post_slug 가 null 이다.
     * 뷰가 그 값을 그대로 URL 헬퍼에 넘기면 타입 오류로 대시보드 전체가 500 이 된다.
     */
    public function testDashboardRendersWhenRecentCommentPostIsMissing(): void
    {
        $admin = $this->makeAdmin();
        $post  = $this->makePost($admin->id);
        $this->commentDaysAgo($post->id, $admin->id, 1);

        // 운영 SQLite 는 FK 가 적용되지 않아 고아 댓글이 실재한다(#110 이 db:prune 을
        // 만든 부채가 그것이다). 테스트 DB 는 foreignKeys=true 라 CASCADE 로 댓글까지
        // 지워지므로, 그 상태를 재현하려면 제약을 잠시 꺼야 한다.
        $this->deletePostIgnoringForeignKeys($post->id);

        $result = $this->actingAs($admin)->call('GET', 'admin');

        $result->assertStatus(200);
        $this->assertStringContainsString('(삭제된 글)', $this->decodedBody($result));
    }
}
