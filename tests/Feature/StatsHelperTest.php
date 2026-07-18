<?php

namespace Tests\Feature;

use App\Entities\Post;
use App\Models\CommentModel;
use App\Models\PostModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * weekly_delta(): created_at 기준 '최근 7일 신규 - 그 이전 7일 신규'.
 */
final class StatsHelperTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();
        helper('stats');
    }

    private function makeUser(): User
    {
        $users = auth()->getProvider();
        $user  = new User(['username' => 'u', 'email' => 'u@example.com', 'password' => 'secret-password-123']);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    private function makePost(int $userId): Post
    {
        $posts = model(PostModel::class);
        $posts->insert(['user_id' => $userId, 'title' => '글', 'body' => '본문', 'status' => Post::STATUS_PUBLISHED]);

        return $posts->find($posts->getInsertID());
    }

    /** created_at 은 allowedFields 밖이라, 며칠 전 댓글을 만들려면 DB 에 직접 박는다. */
    private function commentDaysAgo(int $postId, int $userId, int $daysAgo): void
    {
        $model = model(CommentModel::class);
        $model->insert(['post_id' => $postId, 'user_id' => $userId, 'body' => '댓글']);
        $id = $model->getInsertID();
        db_connect()->table('comments')->where('id', $id)
            ->update(['created_at' => date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"))]);
    }

    public function testPositiveWhenThisWeekExceedsPrevWeek(): void
    {
        $user = $this->makeUser();
        $post = $this->makePost($user->id);
        $this->commentDaysAgo($post->id, $user->id, 2);  // 이번 주
        $this->commentDaysAgo($post->id, $user->id, 3);  // 이번 주
        $this->commentDaysAgo($post->id, $user->id, 4);  // 이번 주
        $this->commentDaysAgo($post->id, $user->id, 10); // 지난 주
        $this->commentDaysAgo($post->id, $user->id, 20); // 그 이전(무시)

        $this->assertSame(2, weekly_delta(model(CommentModel::class))); // 3 - 1
    }

    public function testNegativeWhenPrevWeekExceedsThisWeek(): void
    {
        $user = $this->makeUser();
        $post = $this->makePost($user->id);
        $this->commentDaysAgo($post->id, $user->id, 2);  // 이번 주 1
        $this->commentDaysAgo($post->id, $user->id, 9);  // 지난 주
        $this->commentDaysAgo($post->id, $user->id, 11); // 지난 주
        $this->commentDaysAgo($post->id, $user->id, 13); // 지난 주

        $this->assertSame(-2, weekly_delta(model(CommentModel::class))); // 1 - 3
    }

    public function testZeroWhenEqual(): void
    {
        $user = $this->makeUser();
        $post = $this->makePost($user->id);
        $this->commentDaysAgo($post->id, $user->id, 1);  // 이번 주
        $this->commentDaysAgo($post->id, $user->id, 8);  // 지난 주

        $this->assertSame(0, weekly_delta(model(CommentModel::class))); // 1 - 1
    }

    public function testOlderThanTwoWeeksIsIgnored(): void
    {
        $user = $this->makeUser();
        $post = $this->makePost($user->id);
        $this->commentDaysAgo($post->id, $user->id, 15); // 2주보다 오래됨
        $this->commentDaysAgo($post->id, $user->id, 30);

        $this->assertSame(0, weekly_delta(model(CommentModel::class)));
    }
}
