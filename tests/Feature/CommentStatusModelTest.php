<?php

namespace Tests\Feature;

use App\Entities\Comment;
use App\Models\CommentModel;
use App\Models\PostModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 댓글 상태(status)와 답글(parent_id)의 모델·엔티티 규칙.
 */
final class CommentStatusModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    private int $postId;

    protected function setUp(): void
    {
        parent::setUp();

        // comments.post_id 는 posts.id 를 참조하는 FK 다(CreateCommentsTable, 테스트도
        // foreignKeys=true 로 돈다). 실제로 존재하는 글이 있어야 댓글을 끼워 넣을 수 있다.
        $posts = model(PostModel::class);
        $posts->insert([
            'user_id' => 1,
            'title'   => '댓글 테스트용 글',
            'body'    => '본문',
        ]);
        $this->postId = $posts->getInsertID();
    }

    private function insertComment(array $overrides = []): int
    {
        $model = model(CommentModel::class);
        $model->insert(array_merge([
            'post_id' => $this->postId,
            'user_id' => 1,
            'body'    => '댓글 본문',
        ], $overrides));

        return $model->getInsertID();
    }

    public function testNewCommentIsVisibleByDefault(): void
    {
        $id = $this->insertComment();

        $comment = model(CommentModel::class)->find($id);

        $this->assertSame(Comment::STATUS_VISIBLE, $comment->status);
        $this->assertFalse($comment->isHidden());
    }

    public function testCommentCanBeHidden(): void
    {
        $id = $this->insertComment();

        model(CommentModel::class)->update($id, ['status' => Comment::STATUS_HIDDEN]);

        $comment = model(CommentModel::class)->find($id);
        $this->assertTrue($comment->isHidden());
    }

    public function testUnknownStatusIsRejected(): void
    {
        $id = $this->insertComment();

        $ok = model(CommentModel::class)->update($id, ['status' => 'spam']);

        $this->assertFalse($ok, '허용 목록 밖의 상태는 거부되어야 한다.');
        $this->assertSame(Comment::STATUS_VISIBLE, model(CommentModel::class)->find($id)->status);
    }

    public function testReplyKnowsItIsAReply(): void
    {
        $parentId = $this->insertComment();
        $replyId  = $this->insertComment(['parent_id' => $parentId, 'body' => '답글 본문']);

        $model = model(CommentModel::class);

        $this->assertFalse($model->find($parentId)->isReply());
        $this->assertTrue($model->find($replyId)->isReply());
        $this->assertSame($parentId, (int) $model->find($replyId)->parent_id);
    }

    public function testDeletingParentDeletesItsReplies(): void
    {
        $parentId = $this->insertComment();
        $replyId  = $this->insertComment(['parent_id' => $parentId, 'body' => '답글 본문']);

        model(CommentModel::class)->delete($parentId);

        $this->dontSeeInDatabase('comments', ['id' => $replyId]);
    }

    /**
     * 답글은 설계상 1단계만 허용되지만(컨트롤러가 "답글에 답글"을 막는다), 데이터가 어떤
     * 경로로든 2단계 이상 중첩되었을 때 delete()가 손자·증손 답글까지 지우는지 검증한다.
     * MySQL 의 자기참조 ON DELETE CASCADE 는 재귀적으로 동작하므로, 애플리케이션 쪽 삭제도
     * 재귀적이어야 두 환경의 동작이 같아진다.
     */
    public function testDeletingGrandparentDeletesNestedReplies(): void
    {
        $grandparentId = $this->insertComment();
        $parentId      = $this->insertComment(['parent_id' => $grandparentId, 'body' => '답글 본문']);
        $childId       = $this->insertComment(['parent_id' => $parentId, 'body' => '답글의 답글 본문']);

        model(CommentModel::class)->delete($grandparentId);

        $this->dontSeeInDatabase('comments', ['id' => $parentId]);
        $this->dontSeeInDatabase('comments', ['id' => $childId]);
    }
}
