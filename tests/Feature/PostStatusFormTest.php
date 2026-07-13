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

    public function testUpdateFallsBackToPublishedOnUnknownStatus(): void
    {
        $user  = $this->makeUser();
        $posts = model(PostModel::class);
        $posts->insert(['user_id' => $user->id, 'title' => '초안 상태로 시작한 글', 'body' => '본문', 'status' => Post::STATUS_DRAFT]);
        $id = $posts->getInsertID();

        // 폼 조작으로 이상한 값이 와도 검증 실패가 아니라 published 로 정규화되어야 한다.
        // (normalizeStatus 가 update() 에서 빠지면 in_list 규칙이 그대로 거부해
        // 원래 상태인 draft 가 유지되므로, 이 값으로 두 경우를 구분할 수 있다)
        $result = $this->actingAs($user)->call('POST', "posts/{$id}", [
            'title'  => '초안 상태로 시작한 글',
            'body'   => '본문',
            'status' => 'archived',
        ]);

        $result->assertRedirect();
        $this->seeInDatabase('posts', ['id' => $id, 'status' => 'published']);
    }

    public function testUpdateDefaultsToPublishedWhenStatusMissing(): void
    {
        $user  = $this->makeUser();
        $posts = model(PostModel::class);
        $posts->insert(['user_id' => $user->id, 'title' => '초안 상태로 시작한 글 2', 'body' => '본문', 'status' => Post::STATUS_DRAFT]);
        $id = $posts->getInsertID();

        // status 키 자체를 보내지 않아도 published 로 정규화되어야 한다.
        $result = $this->actingAs($user)->call('POST', "posts/{$id}", [
            'title' => '초안 상태로 시작한 글 2',
            'body'  => '본문',
        ]);

        $result->assertRedirect();
        $this->seeInDatabase('posts', ['id' => $id, 'status' => 'published']);
    }

    public function testCreateFallsBackToPublishedOnArrayStatus(): void
    {
        $user = $this->makeUser();

        // status[]=x 처럼 배열로 조작해 보내도 500 이 아니라 published 로 정규화되어야 한다.
        $result = $this->actingAs($user)->call('POST', 'posts', [
            'title'  => '배열로 조작된 상태 글',
            'body'   => '본문',
            'status' => ['x'],
        ]);

        $result->assertRedirect();
        $this->seeInDatabase('posts', ['title' => '배열로 조작된 상태 글', 'status' => 'published']);
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
