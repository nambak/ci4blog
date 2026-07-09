<?php

namespace Tests\Feature;

use App\Entities\Post;
use App\Models\PostModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * 글 작성/수정 폼에서 상태를 저장하는지 확인한다.
 */
final class PostStatusFormTest extends CIUnitTestCase
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
        $user  = new User(['username' => 'writer', 'email' => 'writer@example.com', 'password' => 'secret-password-123']);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    public function testCreateStoresChosenStatus(): void
    {
        $user = $this->makeUser();

        $result = $this->actingAs($user)->call('POST', 'posts', [
            'title'  => '초안으로 저장할 글',
            'body'   => '본문',
            'status' => Post::STATUS_DRAFT,
        ]);

        $result->assertRedirect();
        $this->seeInDatabase('posts', ['title' => '초안으로 저장할 글', 'status' => 'draft']);
    }

    public function testCreateDefaultsToPublishedWhenStatusMissing(): void
    {
        $user = $this->makeUser();

        $result = $this->actingAs($user)->call('POST', 'posts', [
            'title' => '상태 없이 저장할 글',
            'body'  => '본문',
        ]);

        $result->assertRedirect();
        $this->seeInDatabase('posts', ['title' => '상태 없이 저장할 글', 'status' => 'published']);
    }

    public function testCreateFallsBackToPublishedOnUnknownStatus(): void
    {
        $user = $this->makeUser();

        // 폼 조작으로 이상한 값이 와도 검증 실패가 아니라 published 로 정규화한다.
        $result = $this->actingAs($user)->call('POST', 'posts', [
            'title'  => '조작된 상태 글',
            'body'   => '본문',
            'status' => 'archived',
        ]);

        $result->assertRedirect();
        $this->seeInDatabase('posts', ['title' => '조작된 상태 글', 'status' => 'published']);
    }

    public function testUpdateChangesStatus(): void
    {
        $user  = $this->makeUser();
        $posts = model(PostModel::class);
        $posts->insert(['user_id' => $user->id, 'title' => '원래 공개된 글', 'body' => '본문']);
        $id = $posts->getInsertID();

        $result = $this->actingAs($user)->call('POST', "posts/{$id}", [
            'title'  => '원래 공개된 글',
            'body'   => '본문',
            'status' => Post::STATUS_PRIVATE,
        ]);

        $result->assertRedirect();
        $this->seeInDatabase('posts', ['id' => $id, 'status' => 'private']);
    }

    public function testCreateFormShowsStatusSelect(): void
    {
        $user = $this->makeUser();

        $result = $this->actingAs($user)->call('GET', 'posts/new');

        $result->assertStatus(200);
        $this->assertStringContainsString('name="status"', $result->getBody());
        $result->assertSee('임시저장');
    }
}
