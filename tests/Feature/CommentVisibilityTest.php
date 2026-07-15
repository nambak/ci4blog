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
 * 공개 글 상세에서 댓글이 어떻게 보이는지 — 숨김 규칙과 답글 표시.
 */
final class CommentVisibilityTest extends CIUnitTestCase
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

        return $model->getInsertID();
    }

    public function testHiddenCommentIsNotShown(): void
    {
        $user = $this->makeUser();
        $post = $this->makePost($user->id);
        $this->insertComment($post->id, $user->id, '보이는 댓글');
        $this->insertComment($post->id, $user->id, '숨긴 댓글', ['status' => Comment::STATUS_HIDDEN]);

        $result = $this->call('GET', 'posts/' . $post->slug);

        $result->assertStatus(200);
        $result->assertSee('보이는 댓글');
        $result->assertDontSee('숨긴 댓글');
    }

    public function testReplyOfHiddenParentIsAlsoHidden(): void
    {
        $user     = $this->makeUser();
        $post     = $this->makePost($user->id);
        $parentId = $this->insertComment($post->id, $user->id, '숨긴 부모', ['status' => Comment::STATUS_HIDDEN]);
        $this->insertComment($post->id, $user->id, '부모가 숨겨진 답글', ['parent_id' => $parentId]);

        $result = $this->call('GET', 'posts/' . $post->slug);

        $result->assertStatus(200);
        $result->assertDontSee('숨긴 부모');
        $result->assertDontSee('부모가 숨겨진 답글');
    }

    public function testRestoringParentBringsBackOnlyTheParentAndItsVisibleReplies(): void
    {
        $user     = $this->makeUser();
        $post     = $this->makePost($user->id);
        $parentId = $this->insertComment($post->id, $user->id, '부모 댓글', ['status' => Comment::STATUS_HIDDEN]);
        $this->insertComment($post->id, $user->id, '보이는 답글', ['parent_id' => $parentId]);
        $this->insertComment($post->id, $user->id, '따로 숨긴 답글', ['parent_id' => $parentId, 'status' => Comment::STATUS_HIDDEN]);

        // 부모를 복원한다. 개별로 숨겨 둔 답글은 숨긴 채로 남아야 한다.
        model(CommentModel::class)->update($parentId, ['status' => Comment::STATUS_VISIBLE]);

        $result = $this->call('GET', 'posts/' . $post->slug);

        $result->assertStatus(200);
        $result->assertSee('부모 댓글');
        $result->assertSee('보이는 답글');
        $result->assertDontSee('따로 숨긴 답글');
    }

    public function testReplyIsShownUnderItsParent(): void
    {
        $user     = $this->makeUser();
        $post     = $this->makePost($user->id);
        $parentId = $this->insertComment($post->id, $user->id, '부모 댓글');
        $this->insertComment($post->id, $user->id, '달린 답글', ['parent_id' => $parentId]);

        $comments = model(CommentModel::class)->forPost((int) $post->id);

        // 최상위만 배열에 오고, 답글은 부모 안에 들어간다.
        $this->assertCount(1, $comments);
        $this->assertSame('부모 댓글', $comments[0]->body);
        $this->assertCount(1, $comments[0]->replies);
        $this->assertSame('달린 답글', $comments[0]->replies[0]->body);
    }

    public function testCommentCountExcludesHiddenAndIncludesReplies(): void
    {
        $user     = $this->makeUser();
        $post     = $this->makePost($user->id);
        $parentId = $this->insertComment($post->id, $user->id, '부모 댓글');
        $this->insertComment($post->id, $user->id, '달린 답글', ['parent_id' => $parentId]);
        $this->insertComment($post->id, $user->id, '숨긴 댓글', ['status' => Comment::STATUS_HIDDEN]);

        // 보이는 것: 부모 1 + 답글 1 = 2. 숨긴 1 은 빠진다.
        $this->assertSame(2, model(CommentModel::class)->countForPost((int) $post->id));

        $result = $this->call('GET', 'posts/' . $post->slug);
        $result->assertStatus(200);
        $result->assertSee('2', '.comments-count');
    }

    public function testCountExcludesReplyOfHiddenParentEvenWhenReplyItselfIsVisible(): void
    {
        $user     = $this->makeUser();
        $post     = $this->makePost($user->id);
        $parentId = $this->insertComment($post->id, $user->id, '숨긴 부모', ['status' => Comment::STATUS_HIDDEN]);
        // 답글 자신의 status 는 visible(오버라이드 없음)이지만, 부모가 숨겨졌으므로 함께 빠져야 한다.
        $this->insertComment($post->id, $user->id, '부모가 숨겨진 답글', ['parent_id' => $parentId]);

        $this->assertSame(0, model(CommentModel::class)->countForPost((int) $post->id));

        $result = $this->call('GET', 'posts/' . $post->slug);
        $result->assertStatus(200);
        $result->assertSee('0', '.comments-count');
    }
}
