<?php

namespace Tests\Feature;

use App\Entities\Post;
use App\Models\CommentLikeModel;
use App\Models\CommentModel;
use App\Models\PostModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 댓글 좋아요 모델의 일괄 집계(#100).
 *
 * 목록의 모든 댓글에 카운트가 붙으므로 댓글마다 조회하면 그대로 N+1 이다.
 * CommentReportModel::pendingCountsByComment() 와 같은 형태로 한 번에 가져온다.
 */
final class CommentLikeModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    private function makeUser(string $username, string $email): User
    {
        $users = auth()->getProvider();
        $user  = new User(['username' => $username, 'email' => $email, 'password' => 'secret-password-123']);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    private function makePost(int $userId): Post
    {
        $posts = model(PostModel::class);
        $posts->insert(['user_id' => $userId, 'title' => '글', 'body' => '본문', 'status' => Post::STATUS_PUBLISHED]);

        return $posts->find($posts->getInsertID());
    }

    private function insertComment(int $postId, int $userId, string $body): int
    {
        $model = model(CommentModel::class);
        $model->insert(['post_id' => $postId, 'user_id' => $userId, 'body' => $body]);

        return $model->getInsertID();
    }

    private function like(int $commentId, int $userId): void
    {
        model(CommentLikeModel::class)->insert(['comment_id' => $commentId, 'user_id' => $userId]);
    }

    public function testCountsByCommentReturnsCountPerComment(): void
    {
        $author = $this->makeUser('author', 'author@example.com');
        $u1     = $this->makeUser('u1', 'u1@example.com');
        $u2     = $this->makeUser('u2', 'u2@example.com');
        $post   = $this->makePost($author->id);

        $a = $this->insertComment($post->id, $author->id, '댓글 A');
        $b = $this->insertComment($post->id, $author->id, '댓글 B');
        $c = $this->insertComment($post->id, $author->id, '댓글 C');

        $this->like($a, $u1->id);
        $this->like($a, $u2->id);
        $this->like($b, $u1->id);

        $counts = model(CommentLikeModel::class)->countsByComment([$a, $b, $c]);

        $this->assertSame(2, $counts[$a] ?? 0);
        $this->assertSame(1, $counts[$b] ?? 0);
        // 좋아요가 없는 댓글은 키 자체가 없어야 한다(0 을 넣어 부풀리지 않는다).
        $this->assertArrayNotHasKey($c, $counts);
    }

    public function testCountsByCommentIgnoresCommentsOutsideTheList(): void
    {
        $author = $this->makeUser('author', 'author@example.com');
        $u1     = $this->makeUser('u1', 'u1@example.com');
        $post   = $this->makePost($author->id);

        $asked   = $this->insertComment($post->id, $author->id, '물어본 댓글');
        $unasked = $this->insertComment($post->id, $author->id, '안 물어본 댓글');

        $this->like($asked, $u1->id);
        $this->like($unasked, $u1->id);

        $counts = model(CommentLikeModel::class)->countsByComment([$asked]);

        $this->assertSame([$asked => 1], $counts, '요청한 id 만 돌려줘야 한다');
    }

    public function testCountsByCommentReturnsEmptyForEmptyInput(): void
    {
        // 빈 배열에 whereIn 을 걸면 드라이버에 따라 전체 조회가 되거나 오류가 난다.
        $this->assertSame([], model(CommentLikeModel::class)->countsByComment([]));
    }

    public function testLikedByUserReturnsOnlyThatUsersLikes(): void
    {
        $author = $this->makeUser('author', 'author@example.com');
        $me     = $this->makeUser('me', 'me@example.com');
        $other  = $this->makeUser('other', 'other@example.com');
        $post   = $this->makePost($author->id);

        $mine  = $this->insertComment($post->id, $author->id, '내가 누른 댓글');
        $their = $this->insertComment($post->id, $author->id, '남만 누른 댓글');

        $this->like($mine, $me->id);
        $this->like($their, $other->id);

        $liked = model(CommentLikeModel::class)->likedByUser([$mine, $their], (int) $me->id);

        $this->assertArrayHasKey($mine, $liked);
        $this->assertArrayNotHasKey($their, $liked, '남이 누른 것이 내 것으로 잡히면 안 된다');
    }

    public function testLikedByUserReturnsEmptyForEmptyInput(): void
    {
        $me = $this->makeUser('me', 'me@example.com');

        $this->assertSame([], model(CommentLikeModel::class)->likedByUser([], (int) $me->id));
    }

    public function testHasLikedReflectsSingleRow(): void
    {
        $author = $this->makeUser('author', 'author@example.com');
        $me     = $this->makeUser('me', 'me@example.com');
        $post   = $this->makePost($author->id);
        $c      = $this->insertComment($post->id, $author->id, '댓글');

        $likes = model(CommentLikeModel::class);

        $this->assertFalse($likes->hasLiked($c, (int) $me->id));
        $this->like($c, $me->id);
        $this->assertTrue($likes->hasLiked($c, (int) $me->id));
    }
}
