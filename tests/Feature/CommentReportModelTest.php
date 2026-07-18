<?php

namespace Tests\Feature;

use App\Models\CommentModel;
use App\Models\CommentReportModel;
use App\Models\PostModel;
use App\Entities\Post;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

final class CommentReportModelTest extends CIUnitTestCase
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

    private function report(int $commentId, int $userId, string $reason, string $status = 'pending'): void
    {
        model(CommentReportModel::class)->insert([
            'comment_id'       => $commentId,
            'reporter_user_id' => $userId,
            'reason'           => $reason,
            'status'           => $status,
        ]);
    }

    public function testRejectsUnknownReason(): void
    {
        $user    = $this->makeUser('u', 'u@example.com');
        $post    = $this->makePost($user->id);
        $comment = $this->insertComment($post->id, $user->id, '댓글');

        $ok = model(CommentReportModel::class)->insert([
            'comment_id'       => $comment,
            'reporter_user_id' => $user->id,
            'reason'           => 'not-a-reason',
            'status'           => 'pending',
        ]);

        $this->assertFalse($ok);
        $this->dontSeeInDatabase('comment_reports', ['comment_id' => $comment]);
    }

    public function testHasReportedIsTrueOnlyAfterReport(): void
    {
        $user    = $this->makeUser('u', 'u@example.com');
        $post    = $this->makePost($user->id);
        $comment = $this->insertComment($post->id, $user->id, '댓글');

        $model = model(CommentReportModel::class);
        $this->assertFalse($model->hasReported($comment, $user->id));

        $this->report($comment, $user->id, 'spam');
        $this->assertTrue($model->hasReported($comment, $user->id));
    }

    public function testPendingCountsByCommentCountsOnlyPending(): void
    {
        $author   = $this->makeUser('a', 'a@example.com');
        $r1       = $this->makeUser('r1', 'r1@example.com');
        $r2       = $this->makeUser('r2', 'r2@example.com');
        $post     = $this->makePost($author->id);
        $c1       = $this->insertComment($post->id, $author->id, '댓글1');
        $c2       = $this->insertComment($post->id, $author->id, '댓글2');

        $this->report($c1, $r1->id, 'spam');           // pending
        $this->report($c1, $r2->id, 'abuse');          // pending
        $this->report($c2, $r1->id, 'etc', 'reviewed'); // reviewed → 안 셈

        $counts = model(CommentReportModel::class)->pendingCountsByComment([$c1, $c2]);

        $this->assertSame(2, $counts[$c1] ?? 0);
        $this->assertArrayNotHasKey($c2, $counts);
    }

    public function testMarkReviewedForCommentsFlipsPendingOnly(): void
    {
        $author = $this->makeUser('a', 'a@example.com');
        $r1     = $this->makeUser('r1', 'r1@example.com');
        $r2     = $this->makeUser('r2', 'r2@example.com');
        $r3     = $this->makeUser('r3', 'r3@example.com');
        $post   = $this->makePost($author->id);
        $c1     = $this->insertComment($post->id, $author->id, '댓글1'); // 대상 댓글
        $c2     = $this->insertComment($post->id, $author->id, '댓글2'); // 대상이 아닌 댓글

        $this->report($c1, $r1->id, 'spam');            // c1 · pending  → reviewed 로 바뀌어야 함
        $this->report($c1, $r2->id, 'etc', 'reviewed');  // c1 · 이미 reviewed → 문제없이 그대로여야 함
        $this->report($c2, $r1->id, 'abuse');            // c2 · pending  → 대상 댓글이 아니므로 그대로 남아야 함(whereIn 범위)

        // status 필터가 실제로 걸리는지는 pending/reviewed 두 값만으론 드러나지 않는다
        // (필터를 지워도 reviewed 행은 reviewed→reviewed 로 무변화라 구분이 안 됨).
        // 모델 검증(in_list[pending,reviewed])을 우회해 제3의 감시용 상태값을 심어,
        // markReviewedForComments 가 comment_id 범위 안에서도 pending 만 건드리는지 드러낸다.
        $this->hasInDatabase('comment_reports', [
            'comment_id'       => $c1,
            'reporter_user_id' => $r3->id,
            'reason'           => 'spam',
            'status'           => 'flagged',
        ]);

        model(CommentReportModel::class)->markReviewedForComments([$c1]);

        // c1 · pending 이었던 신고만 reviewed 로 바뀐다.
        $this->seeInDatabase('comment_reports', ['comment_id' => $c1, 'reporter_user_id' => $r1->id, 'status' => 'reviewed']);
        // c1 · 이미 reviewed 였던 신고는 그대로 reviewed.
        $this->seeInDatabase('comment_reports', ['comment_id' => $c1, 'reporter_user_id' => $r2->id, 'status' => 'reviewed']);
        // c1 · pending/reviewed 가 아닌 상태는 손대지 않는다 — status 필터가 실제로 걸린다는 방증.
        $this->seeInDatabase('comment_reports', ['comment_id' => $c1, 'reporter_user_id' => $r3->id, 'status' => 'flagged']);
        // c2 · 대상 댓글이 아니므로 pending 그대로 — whereIn 범위 방어.
        $this->seeInDatabase('comment_reports', ['comment_id' => $c2, 'reporter_user_id' => $r1->id, 'status' => 'pending']);

        $this->assertSame([], model(CommentReportModel::class)->pendingCountsByComment([$c1]));
        $this->assertSame([$c2 => 1], model(CommentReportModel::class)->pendingCountsByComment([$c2]));
    }
}
