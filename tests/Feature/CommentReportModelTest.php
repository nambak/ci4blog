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
        $post   = $this->makePost($author->id);
        $c1     = $this->insertComment($post->id, $author->id, '댓글1');

        $this->report($c1, $r1->id, 'spam'); // pending

        model(CommentReportModel::class)->markReviewedForComments([$c1]);

        $this->seeInDatabase('comment_reports', ['comment_id' => $c1, 'status' => 'reviewed']);
        $this->assertSame([], model(CommentReportModel::class)->pendingCountsByComment([$c1]));
    }
}
