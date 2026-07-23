<?php

namespace Tests\Feature;

use App\Models\PostModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 글을 지울 때 딸린 행이 남지 않는지 본다.
 *
 * **comments 는 단언하지 않는다.** 테스트 DB(SQLite)는 foreignKeys=true 이고
 * db_comments 에만 FK 가 선언돼 있어, 글을 지우면 댓글은 FK 가 먼저 지운다.
 * 즉 이 클래스의 구현이 없어도 "댓글이 사라졌다"는 통과한다 — 위양성이다.
 * FK 가 없는 세 테이블(post_likes·comment_likes·comment_reports)이 진짜 검증 대상이다.
 *
 * 오히려 그 FK CASCADE 때문에 CommentModel::delete() 가 호출되지 않아
 * 댓글의 좋아요·신고가 고아로 남는 것이 이 작업의 핵심 문제다.
 */
final class PostDeleteCleanupTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    /**
     * 글 1건 + 댓글 1건 + 각 자식 1건을 만든다.
     *
     * @return array{post:int, comment:int}
     */
    private function makeFixture(): array
    {
        $db = db_connect();

        $db->table('posts')->insert([
            'title'      => '삭제 정리 테스트 글',
            'slug'       => 'delete-cleanup-' . uniqid(),
            'body'       => '본문',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $postId = (int) $db->insertID();

        $db->table('comments')->insert([
            'post_id'    => $postId,
            'user_id'    => 1,
            'body'       => '댓글',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $commentId = (int) $db->insertID();

        $db->table('post_likes')->insert([
            'post_id'    => $postId,
            'user_id'    => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $db->table('comment_likes')->insert([
            'comment_id' => $commentId,
            'user_id'    => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $db->table('comment_reports')->insert([
            'comment_id'       => $commentId,
            'reporter_user_id' => 1,
            'reason'           => 'spam',
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        return ['post' => $postId, 'comment' => $commentId];
    }

    /** @param array{post:int, comment:int} $f */
    private function assertChildrenGone(array $f): void
    {
        $db = db_connect();

        $this->assertSame(
            0,
            $db->table('post_likes')->where('post_id', $f['post'])->countAllResults(),
            'post_likes 가 남았다'
        );
        $this->assertSame(
            0,
            $db->table('comment_likes')->where('comment_id', $f['comment'])->countAllResults(),
            'comment_likes 가 남았다'
        );
        $this->assertSame(
            0,
            $db->table('comment_reports')->where('comment_id', $f['comment'])->countAllResults(),
            'comment_reports 가 남았다'
        );
    }

    public function testDeletingPostRemovesChildRows(): void
    {
        $f = $this->makeFixture();

        model(PostModel::class)->delete($f['post']);

        $this->assertChildrenGone($f);
    }

    /** Admin 일괄 삭제가 배열을 넘긴다 — 단일 id 만 테스트하면 이 경로가 조용히 깨진다. */
    public function testDeletingPostsByArrayRemovesChildRows(): void
    {
        $a = $this->makeFixture();
        $b = $this->makeFixture();

        model(PostModel::class)->delete([$a['post'], $b['post']]);

        $this->assertChildrenGone($a);
        $this->assertChildrenGone($b);
    }

    /** 다른 글의 자식까지 쓸어 가면 안 된다. 무차별 삭제 뮤테이션을 잡는다. */
    public function testDeletingPostKeepsOtherPostsChildRows(): void
    {
        $target = $this->makeFixture();
        $kept   = $this->makeFixture();

        model(PostModel::class)->delete($target['post']);

        $db = db_connect();
        $this->assertSame(
            1,
            $db->table('post_likes')->where('post_id', $kept['post'])->countAllResults(),
            '다른 글의 post_likes 까지 지웠다'
        );
        $this->assertSame(
            1,
            $db->table('comment_likes')->where('comment_id', $kept['comment'])->countAllResults(),
            '다른 글의 comment_likes 까지 지웠다'
        );
        $this->assertSame(
            1,
            $db->table('comment_reports')->where('comment_id', $kept['comment'])->countAllResults(),
            '다른 글의 comment_reports 까지 지웠다'
        );
    }

    /** 글은 실제로 지워져야 한다 — 정리만 하고 본체를 안 지우는 회귀를 막는다. */
    public function testDeletingPostRemovesThePostItself(): void
    {
        $f = $this->makeFixture();

        model(PostModel::class)->delete($f['post']);

        $this->assertNull(model(PostModel::class)->find($f['post']));
    }
}
