<?php

namespace Tests\Feature;

use App\Models\PostModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Shield\Test\AuthenticationTesting;

/**
 * 댓글 저장(POST /posts/{id}/comments)에 대한 Feature 테스트.
 *
 * - 비로그인 사용자는 session 필터에 막혀 댓글을 달 수 없다.
 * - 로그인 사용자는 유효한 입력이면 댓글이 저장된다.
 * - 내용이 비면 저장되지 않는다.
 */
final class CommentStoreTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected $namespace = null;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        // 앞선 테스트의 로그인 세션이 새 나가지 않도록 비운다.
        $_SESSION = [];
        \Config\Services::resetSingle('session');
        \Config\Services::resetSingle('auth');
    }

    private function makeUser(): User
    {
        $users = auth()->getProvider();

        $user = new User([
            'username' => 'commenter',
            'email'    => 'commenter@example.com',
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
            'title'   => '댓글 달 글',
            'body'    => '본문',
        ]);

        return $model->getInsertID();
    }

    public function testGuestCannotStoreComment(): void
    {
        $postId = $this->makePost(1);

        $result = $this->call('POST', "posts/{$postId}/comments", [
            'body' => '게스트 댓글',
        ]);

        $result->assertRedirect();
        $this->dontSeeInDatabase('comments', ['body' => '게스트 댓글']);
    }

    public function testLoggedInUserCanStoreComment(): void
    {
        $user   = $this->makeUser();
        $postId = $this->makePost($user->id);

        $result = $this->actingAs($user)->call('POST', "posts/{$postId}/comments", [
            'body' => '좋은 글이네요!',
        ]);

        $result->assertRedirect();
        $this->seeInDatabase('comments', [
            'post_id' => $postId,
            'user_id' => $user->id,
            'body'    => '좋은 글이네요!',
        ]);
    }

    public function testValidationFailsWithEmptyBody(): void
    {
        $user   = $this->makeUser();
        $postId = $this->makePost($user->id);

        $result = $this->actingAs($user)->call('POST', "posts/{$postId}/comments", [
            'body' => '',
        ]);

        $result->assertRedirect();
        $this->dontSeeInDatabase('comments', ['post_id' => $postId]);
    }
}
