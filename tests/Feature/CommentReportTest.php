<?php

namespace Tests\Feature;

use App\Models\CommentModel;
use App\Models\PostModel;
use App\Entities\Comment;
use App\Entities\Post;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Shield\Test\AuthenticationTesting;

final class CommentReportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
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

    private function makePost(int $userId): Post
    {
        $posts = model(PostModel::class);
        $posts->insert(['user_id' => $userId, 'title' => '글', 'body' => '본문', 'status' => Post::STATUS_PUBLISHED]);

        return $posts->find($posts->getInsertID());
    }

    private function insertComment(int $postId, int $userId, string $body, array $overrides = []): int
    {
        $model = model(CommentModel::class);
        $model->insert(array_merge(['post_id' => $postId, 'user_id' => $userId, 'body' => $body], $overrides));

        return $model->getInsertID();
    }

    public function testLoggedInUserCanReport(): void
    {
        $author   = $this->makeUser('author', 'author@example.com');
        $reporter = $this->makeUser('reporter', 'reporter@example.com');
        $post     = $this->makePost($author->id);
        $comment  = $this->insertComment($post->id, $author->id, '신고 대상');

        $this->actingAs($reporter)->call('POST', 'comments/' . $comment . '/report', ['reason' => 'spam']);

        $this->seeInDatabase('comment_reports', [
            'comment_id'       => $comment,
            'reporter_user_id' => $reporter->id,
            'reason'           => 'spam',
            'status'           => 'pending',
        ]);
    }

    public function testGuestCannotReport(): void
    {
        $author  = $this->makeUser('author', 'author@example.com');
        $post    = $this->makePost($author->id);
        $comment = $this->insertComment($post->id, $author->id, '신고 대상');

        $this->call('POST', 'comments/' . $comment . '/report', ['reason' => 'spam'])->assertRedirect();

        $this->dontSeeInDatabase('comment_reports', ['comment_id' => $comment]);
    }

    public function testCannotReportOwnComment(): void
    {
        $author = $this->makeUser('author', 'author@example.com');
        $post   = $this->makePost($author->id);
        $mine   = $this->insertComment($post->id, $author->id, '내 댓글');

        $this->actingAs($author)->call('POST', 'comments/' . $mine . '/report', ['reason' => 'spam']);

        $this->dontSeeInDatabase('comment_reports', ['comment_id' => $mine]);
    }

    public function testUnknownReasonIsRejected(): void
    {
        $author   = $this->makeUser('author', 'author@example.com');
        $reporter = $this->makeUser('reporter', 'reporter@example.com');
        $post     = $this->makePost($author->id);
        $comment  = $this->insertComment($post->id, $author->id, '신고 대상');

        $this->actingAs($reporter)->call('POST', 'comments/' . $comment . '/report', ['reason' => '해킹시도']);

        $this->dontSeeInDatabase('comment_reports', ['comment_id' => $comment]);
    }

    public function testCannotReportHiddenComment(): void
    {
        $author   = $this->makeUser('author', 'author@example.com');
        $reporter = $this->makeUser('reporter', 'reporter@example.com');
        $post     = $this->makePost($author->id);
        $hidden   = $this->insertComment($post->id, $author->id, '숨긴 댓글', ['status' => Comment::STATUS_HIDDEN]);

        // 이 저장소에서 PageNotFoundException 은 응답이 아니라 예외로 전파된다(AdminCommentsTest 관례).
        // 예외가 call() 에서 던져지므로, insert 이전에 막혔음이 예외 자체로 증명된다.
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->actingAs($reporter)->call('POST', 'comments/' . $hidden . '/report', ['reason' => 'spam']);
    }

    public function testCannotReportReply(): void
    {
        $author   = $this->makeUser('author', 'author@example.com');
        $reporter = $this->makeUser('reporter', 'reporter@example.com');
        $post     = $this->makePost($author->id);
        $parent   = $this->insertComment($post->id, $author->id, '부모');
        $reply    = $this->insertComment($post->id, $author->id, '답글', ['parent_id' => $parent]);

        // PageNotFoundException 이 call() 에서 던져진다 → insert 이전에 막혔음이 증명된다.
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->actingAs($reporter)->call('POST', 'comments/' . $reply . '/report', ['reason' => 'spam']);
    }

    public function testDuplicateReportIsIgnored(): void
    {
        $author   = $this->makeUser('author', 'author@example.com');
        $reporter = $this->makeUser('reporter', 'reporter@example.com');
        $post     = $this->makePost($author->id);
        $comment  = $this->insertComment($post->id, $author->id, '신고 대상');

        $this->actingAs($reporter)->call('POST', 'comments/' . $comment . '/report', ['reason' => 'spam']);
        $this->actingAs($reporter)->call('POST', 'comments/' . $comment . '/report', ['reason' => 'abuse']);

        // 두 번 신고해도 행은 하나뿐이다(유니크 + 중복 가드).
        $this->assertSame(1, model(\App\Models\CommentReportModel::class)
            ->where('comment_id', $comment)->countAllResults());
    }

    public function testReportFormShownToOthersNotToAuthor(): void
    {
        $author   = $this->makeUser('author', 'author@example.com');
        $reporter = $this->makeUser('reporter', 'reporter@example.com');
        $post     = $this->makePost($author->id);
        $this->insertComment($post->id, $author->id, '신고 대상');

        // 신고자(남)에게는 신고 폼이 보인다.
        $seen = $this->actingAs($reporter)->call('GET', 'posts/' . $post->slug)->getBody();
        $this->assertStringContainsString('/report', $seen);

        // 작성자 본인에게는 자기 댓글 신고 폼이 안 보인다.
        $mine = $this->actingAs($author)->call('GET', 'posts/' . $post->slug)->getBody();
        $this->assertStringNotContainsString('/report', $mine);
    }
}
