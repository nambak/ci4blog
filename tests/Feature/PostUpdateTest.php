<?php

namespace Tests\Feature;

use App\Models\PostModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use Tests\Support\Traits\WithCsrf;

/**
 * 글 수정에 대한 Feature 테스트.
 *
 * - 비로그인 사용자는 수정할 수 없다.
 * - 수정 폼에는 기존 값이 채워져 있다.
 * - 로그인 사용자는 유효한 입력이면 글이 수정된다.
 * - 검증에 실패하면 기존 값이 유지된다.
 */
final class PostUpdateTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use WithCsrf;
    use AuthenticationTesting;

    protected $namespace = null;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        // 앞선 테스트의 로그인 세션(actingAs)이 auth 싱글톤에 캐시된 채
        // 새 나가지 않도록, 매 테스트 전에 세션/인증 상태를 비운다.
        $_SESSION = [];
        \Config\Services::resetSingle('session');
        \Config\Services::resetSingle('auth');
    }

    private function makeUser(string $username = 'editor', string $email = 'editor@example.com'): User
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
            'title'   => '원래 제목',
            'body'    => '원래 본문',
            'slug'    => 'post-original',
        ]);

        return $model->getInsertID();
    }

    public function testGuestCannotUpdatePost(): void
    {
        $id = $this->makePost(1);

        $result = $this->call('POST', "posts/{$id}", [
            'title' => '게스트 수정',
            'body'  => '바뀌면 안 된다.',
        ]);

        $result->assertRedirect();
        $this->dontSeeInDatabase('posts', ['title' => '게스트 수정']);
    }

    public function testEditFormShowsExistingValues(): void
    {
        $user = $this->makeUser();
        $id   = $this->makePost($user->id);

        $result = $this->actingAs($user)->call('GET', "posts/{$id}/edit");

        $result->assertStatus(200);
        $result->assertSee('원래 제목');
        $result->assertSee('원래 본문');
    }

    public function testLoggedInUserCanUpdatePost(): void
    {
        $user = $this->makeUser();
        $id   = $this->makePost($user->id);

        $result = $this->actingAs($user)->call('POST', "posts/{$id}", [
            'title' => '수정된 제목',
            'body'  => '수정된 본문',
        ]);

        $result->assertRedirect();
        $this->seeInDatabase('posts', [
            'id'    => $id,
            'title' => '수정된 제목',
        ]);
    }

    public function testNonOwnerCannotSeeEditForm(): void
    {
        $owner    = $this->makeUser();
        $id       = $this->makePost($owner->id);
        $intruder = $this->makeUser('intruder', 'intruder@example.com');

        // 남의 글 수정 폼은 403 으로 막힌다.
        $this->actingAs($intruder)->call('GET', "posts/{$id}/edit")->assertStatus(403);
    }

    public function testNonOwnerCannotUpdatePost(): void
    {
        $owner    = $this->makeUser();
        $id       = $this->makePost($owner->id);
        $intruder = $this->makeUser('intruder', 'intruder@example.com');

        $result = $this->actingAs($intruder)->call('POST', "posts/{$id}", [
            'title' => '침입자 수정',
            'body'  => '바뀌면 안 된다.',
        ]);

        $result->assertStatus(403);
        $this->dontSeeInDatabase('posts', ['title' => '침입자 수정']);
        // 같은 글이 원래 값을 그대로 유지하는지도 확인한다.
        $this->seeInDatabase('posts', [
            'id'    => $id,
            'title' => '원래 제목',
            'body'  => '원래 본문',
        ]);
    }

    public function testUpdateValidationFailsWithEmptyTitle(): void
    {
        $user = $this->makeUser();
        $id   = $this->makePost($user->id);

        $result = $this->actingAs($user)->call('POST', "posts/{$id}", [
            'title' => '',
            'body'  => '제목 비움',
        ]);

        $result->assertRedirect();
        // 검증 실패 시 기존 값이 그대로 남아 있어야 한다.
        $this->seeInDatabase('posts', [
            'id'    => $id,
            'title' => '원래 제목',
        ]);
    }
}
