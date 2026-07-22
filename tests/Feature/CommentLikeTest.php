<?php

namespace Tests\Feature;

use App\Entities\Comment;
use App\Entities\Post;
use App\Models\CategoryModel;
use App\Models\CommentModel;
use App\Models\PostModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\WithCsrf;

/**
 * 댓글 좋아요(#100).
 *
 * 게시글 좋아요(#64)와 같은 토글·중복 방지 구조를 쓰고, 가드만 2단이다
 * (글 접근 가드 + 댓글 자체 상태). POST 라 WithCsrf 를 쓴다(#73).
 */
final class CommentLikeTest extends CIUnitTestCase
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

    private function makeUser(string $username, string $email): User
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
            'title'   => '댓글 좋아요 대상 글',
            'body'    => '본문',
            'status'  => Post::STATUS_PUBLISHED,
        ], $overrides));

        return $posts->find($posts->getInsertID());
    }

    private function makeCategory(string $name, bool $visible): int
    {
        $categories = model(CategoryModel::class);
        $categories->insert(['name' => $name, 'slug' => url_title($name, '-', true), 'is_visible' => $visible ? 1 : 0]);

        return $categories->getInsertID();
    }

    private function makeComment(int $postId, int $userId, array $overrides = []): int
    {
        $comments = model(CommentModel::class);
        $comments->insert(array_merge([
            'post_id' => $postId,
            'user_id' => $userId,
            'body'    => '댓글 본문',
        ], $overrides));

        return $comments->getInsertID();
    }

    /**
     * 좋아요 수를 DB 에서 직접 센다.
     *
     * 모델을 거치지 않는 건 의도적이다 — 모델 메서드가 잘못돼도 테스트는 실제 저장 상태를 본다.
     */
    private function likeCount(int $commentId): int
    {
        return db_connect()->table('comment_likes')->where('comment_id', $commentId)->countAllResults();
    }

    public function testLikeThenUnlikeTogglesTheRow(): void
    {
        $author  = $this->makeUser('author', 'author@example.com');
        $visitor = $this->makeUser('visitor', 'visitor@example.com');
        $post    = $this->makePost($author->id);
        $comment = $this->makeComment($post->id, $author->id);

        $this->actingAs($visitor)->call('POST', "comments/{$comment}/like")->assertRedirect();
        $this->assertSame(1, $this->likeCount($comment), '처음 누르면 좋아요가 생겨야 한다');

        $this->actingAs($visitor)->call('POST', "comments/{$comment}/like")->assertRedirect();
        $this->assertSame(0, $this->likeCount($comment), '한 번 더 누르면 취소돼야 한다');
    }

    public function testDifferentUsersLikesAccumulate(): void
    {
        $author  = $this->makeUser('author', 'author@example.com');
        $u1      = $this->makeUser('u1', 'u1@example.com');
        $u2      = $this->makeUser('u2', 'u2@example.com');
        $post    = $this->makePost($author->id);
        $comment = $this->makeComment($post->id, $author->id);

        $this->actingAs($u1)->call('POST', "comments/{$comment}/like");
        $this->actingAs($u2)->call('POST', "comments/{$comment}/like");

        $this->assertSame(2, $this->likeCount($comment), '사용자가 다르면 각각 쌓여야 한다');
    }

    public function testGuestIsRedirectedAndCountIsUnchanged(): void
    {
        $author  = $this->makeUser('author', 'author@example.com');
        $post    = $this->makePost($author->id);
        $comment = $this->makeComment($post->id, $author->id);

        $result = $this->call('POST', "comments/{$comment}/like");

        $result->assertRedirect();
        $this->assertSame(0, $this->likeCount($comment), '비로그인은 좋아요가 남으면 안 된다');
    }

    public function testReplyCanBeLiked(): void
    {
        $author  = $this->makeUser('author', 'author@example.com');
        $visitor = $this->makeUser('visitor', 'visitor@example.com');
        $post    = $this->makePost($author->id);
        $parent  = $this->makeComment($post->id, $author->id);
        $reply   = $this->makeComment($post->id, $author->id, ['parent_id' => $parent, 'body' => '관리자 답글']);

        $this->actingAs($visitor)->call('POST', "comments/{$reply}/like")->assertRedirect();

        $this->assertSame(1, $this->likeCount($reply), '답글에도 좋아요를 달 수 있어야 한다');
        $this->assertSame(0, $this->likeCount($parent), '부모 댓글에 잘못 달리면 안 된다');
    }

    public function testHiddenCommentCannotBeLiked(): void
    {
        $author  = $this->makeUser('author', 'author@example.com');
        $visitor = $this->makeUser('visitor', 'visitor@example.com');
        $post    = $this->makePost($author->id);
        $comment = $this->makeComment($post->id, $author->id, ['status' => Comment::STATUS_HIDDEN]);

        try {
            $this->actingAs($visitor)->call('POST', "comments/{$comment}/like");
            $this->fail('숨김 댓글에는 좋아요를 달 수 없어야 한다.');
        } catch (PageNotFoundException) {
            // 기대한 경로.
        }

        $this->assertSame(0, $this->likeCount($comment));
    }

    public function testCommentOnUnpublishedPostCannotBeLiked(): void
    {
        $author  = $this->makeUser('author', 'author@example.com');
        $visitor = $this->makeUser('visitor', 'visitor@example.com');
        $post    = $this->makePost($author->id, ['status' => Post::STATUS_DRAFT]);
        $comment = $this->makeComment($post->id, $author->id);

        try {
            $this->actingAs($visitor)->call('POST', "comments/{$comment}/like");
            $this->fail('비발행 글의 댓글에는 좋아요를 달 수 없어야 한다.');
        } catch (PageNotFoundException) {
            // 기대한 경로.
        }

        $this->assertSame(0, $this->likeCount($comment));
    }

    public function testCommentOnPostInHiddenCategoryCannotBeLiked(): void
    {
        $author  = $this->makeUser('author', 'author@example.com');
        $visitor = $this->makeUser('visitor', 'visitor@example.com');
        $post    = $this->makePost($author->id, ['category_id' => $this->makeCategory('숨김분류', false)]);
        $comment = $this->makeComment($post->id, $author->id);

        try {
            $this->actingAs($visitor)->call('POST', "comments/{$comment}/like");
            $this->fail('숨김 카테고리 글의 댓글에는 좋아요를 달 수 없어야 한다.');
        } catch (PageNotFoundException) {
            // 기대한 경로.
        }

        $this->assertSame(0, $this->likeCount($comment));
    }

    public function testMissingCommentIsNotFound(): void
    {
        $visitor = $this->makeUser('visitor', 'visitor@example.com');

        $this->expectException(PageNotFoundException::class);
        $this->actingAs($visitor)->call('POST', 'comments/999999/like');
    }
}
