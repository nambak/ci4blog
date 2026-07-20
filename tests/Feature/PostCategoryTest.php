<?php

namespace Tests\Feature;

use App\Models\CategoryModel;
use App\Models\PostModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use Tests\Support\Traits\WithCsrf;

/**
 * 글 작성/수정 시 카테고리 지정(category_id)에 대한 Feature 테스트.
 *
 * - 폼에서 고른 카테고리가 글에 저장된다.
 * - 카테고리는 선택 사항이라, 안 고르면 null 로 저장된다.
 * - 존재하지 않는 카테고리 id 는 검증에서 막혀 저장되지 않는다.
 * - 수정 시 카테고리를 바꿀 수 있다.
 */
final class PostCategoryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use WithCsrf;
    use AuthenticationTesting;

    protected $namespace = null;
    protected $refresh   = true;

    private int $categoryId;
    private int $otherCategoryId;

    protected function setUp(): void
    {
        parent::setUp();

        // 앞선 테스트의 로그인 세션이 auth 싱글톤에 남지 않도록 초기화.
        $_SESSION = [];
        \Config\Services::resetSingle('session');
        \Config\Services::resetSingle('auth');

        $categories            = model(CategoryModel::class);
        $this->categoryId      = (int) $categories->insert(['name' => '테스트 카테고리', 'slug' => 'test-cat']);
        $this->otherCategoryId = (int) $categories->insert(['name' => '다른 카테고리', 'slug' => 'other-cat']);
    }

    private function makeUser(): User
    {
        $users = auth()->getProvider();

        $user = new User([
            'username' => 'writer',
            'email'    => 'writer@example.com',
            'password' => 'secret-password-123',
        ]);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    public function testStoresSelectedCategory(): void
    {
        $result = $this->actingAs($this->makeUser())
            ->call('POST', 'posts', [
                'title'       => '카테고리 있는 글',
                'body'        => '본문입니다.',
                'category_id' => (string) $this->categoryId,
            ]);

        $result->assertRedirect();
        $this->seeInDatabase('posts', [
            'title'       => '카테고리 있는 글',
            'category_id' => $this->categoryId,
        ]);
    }

    public function testEmptyCategoryStoredAsNull(): void
    {
        $result = $this->actingAs($this->makeUser())
            ->call('POST', 'posts', [
                'title'       => '카테고리 없는 글',
                'body'        => '본문입니다.',
                'category_id' => '',
            ]);

        $result->assertRedirect();
        $this->seeInDatabase('posts', [
            'title'       => '카테고리 없는 글',
            'category_id' => null,
        ]);
    }

    public function testRejectsNonexistentCategory(): void
    {
        $result = $this->actingAs($this->makeUser())
            ->call('POST', 'posts', [
                'title'       => '가짜 카테고리 글',
                'body'        => '본문입니다.',
                'category_id' => '999999',
            ]);

        // 존재하지 않는 카테고리는 검증에 막혀 저장되지 않고 폼으로 되돌아간다.
        $result->assertRedirect();
        $this->dontSeeInDatabase('posts', ['title' => '가짜 카테고리 글']);
    }

    public function testNewFormShowsCategorySelect(): void
    {
        $result = $this->actingAs($this->makeUser())->get('posts/new');

        $result->assertStatus(200);
        // 카테고리 선택 드롭다운과 옵션(카테고리 이름)이 보여야 한다.
        $this->assertStringContainsString('name="category_id"', $result->getBody());
        $result->assertSee('테스트 카테고리');
    }

    public function testEditFormPreselectsCurrentCategory(): void
    {
        $user  = $this->makeUser();
        $posts = model(PostModel::class);
        $id    = (int) $posts->insert([
            'user_id'     => $user->id,
            'category_id' => $this->otherCategoryId,
            'title'       => '편집 폼 글',
            'body'        => '본문',
        ]);

        $result = $this->actingAs($user)->get('posts/' . $id . '/edit');

        $result->assertStatus(200);
        // 현재 카테고리 옵션이 selected 로 표시돼야 한다.
        $this->assertStringContainsString('value="' . $this->otherCategoryId . '" selected', $result->getBody());
    }

    public function testUpdateChangesCategory(): void
    {
        $user  = $this->makeUser();
        $posts = model(PostModel::class);
        $id    = (int) $posts->insert([
            'user_id'     => $user->id,
            'category_id' => $this->categoryId,
            'title'       => '수정 대상 글',
            'body'        => '원래 본문',
        ]);

        $result = $this->actingAs($user)
            ->call('POST', 'posts/' . $id, [
                'title'       => '수정 대상 글',
                'body'        => '원래 본문',
                'category_id' => (string) $this->otherCategoryId,
            ]);

        $result->assertRedirect();
        $this->seeInDatabase('posts', [
            'id'          => $id,
            'category_id' => $this->otherCategoryId,
        ]);
    }
}
