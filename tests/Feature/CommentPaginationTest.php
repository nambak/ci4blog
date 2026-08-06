<?php

namespace Tests\Feature;

use App\Entities\Comment;
use App\Entities\Post;
use App\Models\CommentModel;
use App\Models\PostModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * 댓글 페이지네이션. (#114)
 *
 * 페이지 단위는 행이 아니라 **최상위 댓글**이다 — 답글은 부모를 따라가야
 * 트리가 끊기지 않는다. 최근 최상위 N개를 뽑고 그 부모들의 답글을 함께 가져온다.
 *
 * 댓글 본문에 자리수를 고정한 번호를 쓴다('댓글 01' … '댓글 21').
 * '댓글 1' 로 쓰면 '댓글 10'·'댓글 11' 의 부분 문자열이라
 * assertStringNotContainsString('댓글 1') 이 엉뚱한 것에 걸린다.
 */
final class CommentPaginationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    private function makeUser(): User
    {
        $users = auth()->getProvider();
        $user  = new User(['username' => 'writer', 'email' => 'writer@example.com', 'password' => 'secret-password-123']);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    private function makePost(int $userId): Post
    {
        $posts = model(PostModel::class);
        $posts->insert([
            'user_id' => $userId,
            'title'   => '글 제목',
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

        return (int) $model->getInsertID();
    }

    /** 최상위 댓글 $count 개를 '댓글 01' … 순서로 만든다. 반환은 [번호 => id]. */
    private function seedTopLevel(int $postId, int $userId, int $count): array
    {
        $ids = [];

        for ($i = 1; $i <= $count; $i++) {
            $ids[$i] = $this->insertComment($postId, $userId, sprintf('댓글 %02d', $i));
        }

        return $ids;
    }

    /** @return array{post:Post, user:User} */
    private function seedPost(): array
    {
        $user = $this->makeUser();

        return ['post' => $this->makePost((int) $user->id), 'user' => $user];
    }

    public function testTopLevelIsLimitedToTheMostRecentPage(): void
    {
        ['post' => $post, 'user' => $user] = $this->seedPost();
        $this->seedTopLevel((int) $post->id, (int) $user->id, 21);

        $comments = model(CommentModel::class)->forPost((int) $post->id, 20);

        $bodies = array_map(static fn ($c): string => $c->body, $comments);

        $this->assertCount(20, $comments);
        // 가장 오래된 '댓글 01' 만 빠지고 나머지가 남는다.
        $this->assertNotContains('댓글 01', $bodies);
        $this->assertContains('댓글 02', $bodies);
        $this->assertContains('댓글 21', $bodies);
        // 화면 순서는 오래된 → 최신이다.
        $this->assertSame('댓글 02', $bodies[0]);
        $this->assertSame('댓글 21', $bodies[19]);
    }

    /**
     * 🔴 답글은 부모를 따라간다.
     *
     * 행 단위로 잘랐다면 경계의 부모만 나오고 답글이 잘려 나간다.
     * 경계에 걸린 부모('댓글 02')에 답글을 달아 그것까지 따라오는지 본다.
     */
    public function testRepliesFollowTheirParentAcrossThePageBoundary(): void
    {
        ['post' => $post, 'user' => $user] = $this->seedPost();
        $ids = $this->seedTopLevel((int) $post->id, (int) $user->id, 21);

        $this->insertComment((int) $post->id, (int) $user->id, '경계 답글', ['parent_id' => $ids[2]]);

        $comments = model(CommentModel::class)->forPost((int) $post->id, 20);

        $first = $comments[0];
        $this->assertSame('댓글 02', $first->body);
        $this->assertCount(1, $first->replies);
        $this->assertSame('경계 답글', $first->replies[0]->body);
    }

    public function testNullLimitReturnsEverything(): void
    {
        ['post' => $post, 'user' => $user] = $this->seedPost();
        $this->seedTopLevel((int) $post->id, (int) $user->id, 21);

        $comments = model(CommentModel::class)->forPost((int) $post->id);

        $this->assertCount(21, $comments);
    }

    /**
     * 🔴 countForPost() 는 COUNT 쿼리여야 한다 — 전체 행을 PHP 로 가져와 세면 안 된다.
     *
     * 마지막으로 실행된 쿼리를 직접 본다(getLastQuery() 는 DBDebug 와 무관하게
     * 늘 보관된다). 쿼리 형태만 보면 엉뚱한 수를 세도 통과하므로 값 단언을 함께 둔다.
     */
    public function testCountForPostUsesACountQuery(): void
    {
        ['post' => $post, 'user' => $user] = $this->seedPost();
        $ids = $this->seedTopLevel((int) $post->id, (int) $user->id, 2);
        $this->insertComment((int) $post->id, (int) $user->id, '답글', ['parent_id' => $ids[1]]);

        $count = model(CommentModel::class)->countForPost((int) $post->id);

        // 호출 직후에 읽어야 한다 — 사이에 다른 질의가 끼면 마지막 쿼리가 바뀐다.
        $lastQuery = (string) db_connect()->getLastQuery();

        $this->assertStringContainsStringIgnoringCase('COUNT(', $lastQuery);
        $this->assertSame(3, $count, '최상위 2 + 답글 1');
    }

    public function testCountTopLevelIgnoresReplies(): void
    {
        ['post' => $post, 'user' => $user] = $this->seedPost();
        $ids = $this->seedTopLevel((int) $post->id, (int) $user->id, 2);
        $this->insertComment((int) $post->id, (int) $user->id, '답글', ['parent_id' => $ids[1]]);

        $this->assertSame(2, model(CommentModel::class)->countTopLevelForPost((int) $post->id));
    }

    /**
     * 기존 가시성 규칙이 유지되는지 본다 — 숨긴 댓글과 **숨긴 부모의 답글**이 빠진다.
     * 2단계 조회로 바꾸면서 이 규칙이 새어 나가기 쉬운 자리다.
     */
    public function testHiddenCommentsAndTheirRepliesStayExcluded(): void
    {
        ['post' => $post, 'user' => $user] = $this->seedPost();

        $visible = $this->insertComment((int) $post->id, (int) $user->id, '보이는 댓글');
        $hidden  = $this->insertComment((int) $post->id, (int) $user->id, '숨긴 댓글', ['status' => Comment::STATUS_HIDDEN]);

        $this->insertComment((int) $post->id, (int) $user->id, '보이는 부모의 답글', ['parent_id' => $visible]);
        $this->insertComment((int) $post->id, (int) $user->id, '숨긴 부모의 답글', ['parent_id' => $hidden]);

        $comments = model(CommentModel::class)->forPost((int) $post->id);

        $this->assertCount(1, $comments);
        $this->assertSame('보이는 댓글', $comments[0]->body);
        $this->assertCount(1, $comments[0]->replies);
        $this->assertSame('보이는 부모의 답글', $comments[0]->replies[0]->body);

        // 카운트도 같은 규칙을 따라야 한다: 보이는 댓글 1 + 그 답글 1 = 2
        $this->assertSame(2, model(CommentModel::class)->countForPost((int) $post->id));
    }
}
