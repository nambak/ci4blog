<?php

namespace Tests\Feature;

use App\Models\PostModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Shield\Test\AuthenticationTesting;

/**
 * 댓글 폼의 인증 가드에 대한 Feature 테스트.
 *
 * 댓글 폼은 로그인 사용자에게만 보이고, 비로그인 사용자에게는 보이지 않는다.
 * (저장 라우트 자체의 차단은 CommentStoreTest 에서 검증한다.)
 */
final class CommentGuardTest extends CIUnitTestCase
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

    private function makeUser(): User
    {
        $users = auth()->getProvider();

        $user = new User([
            'username' => 'reader',
            'email'    => 'reader@example.com',
            'password' => 'secret-password-123',
        ]);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    private function makePostSlug(int $userId): string
    {
        $model = model(PostModel::class);
        $model->insert([
            'user_id' => $userId,
            'title'   => '댓글 폼 테스트 글',
            'body'    => '본문',
        ]);

        return $model->find($model->getInsertID())->slug;
    }

    public function testGuestDoesNotSeeCommentForm(): void
    {
        $slug = $this->makePostSlug(1);

        $result = $this->call('GET', 'posts/' . $slug);

        $result->assertStatus(200);
        $result->assertDontSee('남기기');
    }

    public function testLoggedInUserSeesCommentForm(): void
    {
        $user = $this->makeUser();
        $slug = $this->makePostSlug($user->id);

        $result = $this->actingAs($user)->call('GET', 'posts/' . $slug);

        $result->assertStatus(200);
        $result->assertSee('남기기');
    }
}
