<?php

namespace Tests\Feature;

use App\Models\CommentModel;
use App\Models\PostModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use Tests\Support\Traits\WithCsrf;

/**
 * 댓글 삭제와 권한에 대한 Feature 테스트.
 *
 * 삭제 가능: 댓글 작성자 본인 · 글 작성자 · 관리자.
 * 그 외(비로그인 포함)는 삭제할 수 없다.
 */
final class CommentDeleteTest extends CIUnitTestCase
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

        $user = new User([
            'username' => $username,
            'email'    => $email,
            'password' => 'secret-password-123',
        ]);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    private function makePost(int $userId): int
    {
        $model = model(PostModel::class);
        $model->insert([
            'user_id' => $userId,
            'title'   => '댓글 삭제 테스트 글',
            'body'    => '본문',
        ]);

        return $model->getInsertID();
    }

    private function makeComment(int $postId, int $userId): int
    {
        $model = model(CommentModel::class);
        $model->insert([
            'post_id' => $postId,
            'user_id' => $userId,
            'body'    => '지울 댓글',
        ]);

        return $model->getInsertID();
    }

    public function testGuestCannotDeleteComment(): void
    {
        $postId    = $this->makePost(1);
        $commentId = $this->makeComment($postId, 1);

        $result = $this->call('POST', "comments/{$commentId}/delete");

        $result->assertRedirect();
        $this->seeInDatabase('comments', ['id' => $commentId]);
    }

    public function testCommentAuthorCanDeleteOwnComment(): void
    {
        $author    = $this->makeUser('postwriter', 'pw@example.com');
        $commenter = $this->makeUser('commenter', 'c@example.com');
        $postId    = $this->makePost($author->id);
        $commentId = $this->makeComment($postId, $commenter->id);

        $result = $this->actingAs($commenter)->call('POST', "comments/{$commentId}/delete");

        $result->assertRedirect();
        $this->dontSeeInDatabase('comments', ['id' => $commentId]);
    }

    public function testPostAuthorCanDeleteAnyComment(): void
    {
        $author    = $this->makeUser('postwriter', 'pw@example.com');
        $commenter = $this->makeUser('commenter', 'c@example.com');
        $postId    = $this->makePost($author->id);
        $commentId = $this->makeComment($postId, $commenter->id);

        // 글 작성자는 남이 단 댓글도 지울 수 있다.
        $result = $this->actingAs($author)->call('POST', "comments/{$commentId}/delete");

        $result->assertRedirect();
        $this->dontSeeInDatabase('comments', ['id' => $commentId]);
    }

    public function testOtherUserCannotDeleteComment(): void
    {
        $author    = $this->makeUser('postwriter', 'pw@example.com');
        $commenter = $this->makeUser('commenter', 'c@example.com');
        $other     = $this->makeUser('other', 'o@example.com');
        $postId    = $this->makePost($author->id);
        $commentId = $this->makeComment($postId, $commenter->id);

        $result = $this->actingAs($other)->call('POST', "comments/{$commentId}/delete");

        $result->assertStatus(403);
        $this->seeInDatabase('comments', ['id' => $commentId]);
    }

    public function testAdminCanDeleteAnyComment(): void
    {
        $author    = $this->makeUser('postwriter', 'pw@example.com');
        $commenter = $this->makeUser('commenter', 'c@example.com');
        $postId    = $this->makePost($author->id);
        $commentId = $this->makeComment($postId, $commenter->id);

        $admin = $this->makeUser('admin', 'admin@example.com');
        $admin->addGroup('admin');

        $result = $this->actingAs($admin)->call('POST', "comments/{$commentId}/delete");

        $result->assertRedirect();
        $this->dontSeeInDatabase('comments', ['id' => $commentId]);
    }
}
