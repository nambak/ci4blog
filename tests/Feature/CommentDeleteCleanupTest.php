<?php

namespace Tests\Feature;

use App\Models\CommentModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 댓글을 지울 때 딸린 행이 남지 않는지 본다.
 *
 * 답글·comment_reports 정리는 이미 있었고, comment_likes 만 빠져 있었다
 * (그 테이블이 나중에 생겼는데 정리 코드가 따라가지 않았다).
 */
final class CommentDeleteCleanupTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    /** 글 1건을 만들고 id 를 돌려준다. */
    private function makePost(): int
    {
        $db = db_connect();
        $db->table('posts')->insert([
            'title'      => '정리 테스트 글',
            'slug'       => 'cleanup-post-' . uniqid(),
            'body'       => '본문',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    /** 댓글 1건을 만들고 id 를 돌려준다. */
    private function makeComment(int $postId, ?int $parentId = null): int
    {
        $db = db_connect();
        $db->table('comments')->insert([
            'post_id'    => $postId,
            'user_id'    => 1,
            'parent_id'  => $parentId,
            'body'       => '댓글 본문',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    private function likeComment(int $commentId, int $userId): void
    {
        db_connect()->table('comment_likes')->insert([
            'comment_id' => $commentId,
            'user_id'    => $userId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function testDeletingCommentRemovesItsLikes(): void
    {
        $postId    = $this->makePost();
        $commentId = $this->makeComment($postId);
        $this->likeComment($commentId, 1);

        // 지우지 않을 댓글도 하나 둔다. 무차별 삭제(전부 지우기) 뮤테이션을 잡는다.
        $keptId = $this->makeComment($postId);
        $this->likeComment($keptId, 1);

        model(CommentModel::class)->delete($commentId);

        $db = db_connect();
        $this->assertSame(
            0,
            $db->table('comment_likes')->where('comment_id', $commentId)->countAllResults(),
            '지운 댓글의 좋아요가 남았다'
        );
        $this->assertSame(
            1,
            $db->table('comment_likes')->where('comment_id', $keptId)->countAllResults(),
            '지우지 않은 댓글의 좋아요까지 사라졌다'
        );
    }

    /** 답글의 좋아요도 함께 사라져야 한다 — 재귀 정리에 comment_likes 가 물려 있는지 본다. */
    public function testDeletingCommentRemovesReplyLikes(): void
    {
        $postId  = $this->makePost();
        $rootId  = $this->makeComment($postId);
        $replyId = $this->makeComment($postId, $rootId);
        $this->likeComment($replyId, 1);

        model(CommentModel::class)->delete($rootId);

        $this->assertSame(
            0,
            db_connect()->table('comment_likes')->where('comment_id', $replyId)->countAllResults(),
            '답글의 좋아요가 남았다'
        );
    }

    /**
     * id 없이 호출되면 트랜잭션을 열기 전에 위임해, 실패해도 트랜잭션이 미완결로 남지 않는다.
     *
     * transStart() 뒤 parent::delete() 가 예외를 던지면 transComplete() 를 못 타서
     * 트랜잭션 depth 가 1 로 남고, 그 뒤 정상 삭제까지 미완결 트랜잭션에 물린다.
     * PostModel::delete() 와 같은 빈-id 가드로 트랜잭션을 아예 열지 않게 막는다.
     */
    public function testDeletingWithEmptyIdDoesNotLeakTransaction(): void
    {
        $db = db_connect();

        // 빈 id — parent::delete() 의 "where 없는 삭제 금지" 예외는 여기서 검증 대상이 아니다.
        // 잡는 것은 그 예외로 트랜잭션이 열린 채 남는 누수다.
        try {
            model(CommentModel::class)->delete(null);
        } catch (\Throwable) {
            // 무시: 트랜잭션 누수만 본다.
        }

        $this->assertSame(0, $db->transDepth, '빈 id 삭제가 트랜잭션을 열어둔 채 빠져나갔다');
    }
}
