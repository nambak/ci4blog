<?php

namespace Tests\Feature;

use App\Models\CategoryModel;
use App\Models\PostModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Shield\Test\AuthenticationTesting;

/**
 * 관리자 카테고리 관리(/admin/categories) Feature 테스트.
 */
final class AdminCategoriesTest extends CIUnitTestCase
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

    private function makeAdmin(): User
    {
        $admin = $this->makeUser('admin', 'admin@example.com');
        $admin->addGroup('admin');

        return $admin;
    }

    public function testGuestCannotAccess(): void
    {
        $result = $this->call('GET', 'admin/categories');
        $result->assertRedirect();
    }

    public function testNormalUserCannotAccess(): void
    {
        $user   = $this->makeUser('member', 'member@example.com');
        $result = $this->actingAs($user)->call('GET', 'admin/categories');
        $result->assertRedirect();
    }

    public function testAdminSeesListWithCounts(): void
    {
        $admin      = $this->makeAdmin();
        $categories = model(CategoryModel::class);
        $posts      = model(PostModel::class);

        $categories->insert(['name' => '여행기록']);
        $catId = $categories->getInsertID();

        $posts->insert(['user_id' => $admin->id, 'category_id' => $catId, 'title' => '글1', 'body' => '본문']);
        $posts->insert(['user_id' => $admin->id, 'category_id' => null, 'title' => '미분류글', 'body' => '본문']);

        $result = $this->actingAs($admin)->call('GET', 'admin/categories');

        $result->assertStatus(200);
        $result->assertSee('여행기록');
        $result->assertSee('1개 글');       // 정확한 글 수(여행기록: 1건)
        $result->assertSee('미분류');       // 미분류 읽기 전용 행
        $result->assertSee('카테고리', 'h1'); // 페이지 제목
    }

    public function testListSearchFiltersByName(): void
    {
        $admin      = $this->makeAdmin();
        $categories = model(CategoryModel::class);

        $categories->insert(['name' => '여행기록']);
        $categories->insert(['name' => '개발일지']);

        $result = $this->actingAs($admin)->call('GET', 'admin/categories?q=여행');

        $result->assertStatus(200);
        $result->assertSee('여행기록');
        $result->assertDontSee('개발일지');
    }

    public function testAdminCreatesCategoryWithAutoSlug(): void
    {
        $admin = $this->makeAdmin();

        $result = $this->actingAs($admin)->call('POST', 'admin/categories', [
            'name' => '개발 노트',
            'slug' => '',
        ]);

        $result->assertRedirect();
        $this->seeInDatabase('categories', ['name' => '개발 노트', 'slug' => '개발-노트']);
    }

    public function testCreateRejectsEmptyName(): void
    {
        $admin = $this->makeAdmin();

        $result = $this->actingAs($admin)->call('POST', 'admin/categories', [
            'name' => '',
        ]);

        $result->assertRedirect();
        $this->dontSeeInDatabase('categories', ['slug' => 'category']);
    }

    public function testAdminUpdatesCategoryName(): void
    {
        $admin      = $this->makeAdmin();
        $categories = model(CategoryModel::class);
        $categories->insert(['name' => '옛이름']);
        $id = $categories->getInsertID();

        $result = $this->actingAs($admin)->call('POST', "admin/categories/{$id}", [
            'name' => '새이름',
            'slug' => $categories->find($id)->slug,
        ]);

        $result->assertRedirect();
        $this->seeInDatabase('categories', ['id' => $id, 'name' => '새이름']);
    }

    public function testEditMissingCategoryReturns404(): void
    {
        $admin = $this->makeAdmin();

        // 404 는 Feature 테스트에서 응답이 아니라 예외로 전파된다(기존 PostShowTest 관례).
        $this->expectException(PageNotFoundException::class);
        $this->actingAs($admin)->call('GET', 'admin/categories/9999/edit');
    }

    public function testDeleteMovesPostsToUncategorized(): void
    {
        $admin      = $this->makeAdmin();
        $categories = model(CategoryModel::class);
        $posts      = model(PostModel::class);

        $categories->insert(['name' => '지울분류']);
        $catId = $categories->getInsertID();
        $posts->insert(['user_id' => $admin->id, 'category_id' => $catId, 'title' => '딸린글', 'body' => '본문']);
        $postId = $posts->getInsertID();

        $result = $this->actingAs($admin)->call('POST', "admin/categories/{$catId}/delete");

        $result->assertRedirect();
        $this->dontSeeInDatabase('categories', ['id' => $catId]);
        // 딸린 글은 지워지지 않고 미분류(NULL)로 남는다.
        $this->seeInDatabase('posts', ['id' => $postId]);
        $this->assertNull($posts->find($postId)->category_id);
    }

    public function testDeleteMissingCategoryReturns404(): void
    {
        $admin = $this->makeAdmin();

        // 404 는 Feature 테스트에서 예외로 전파된다(기존 PostShowTest 관례). 파일 상단에
        // `use CodeIgniter\Exceptions\PageNotFoundException;` 가 이미 있어야 한다(Task 4에서 추가).
        $this->expectException(PageNotFoundException::class);
        $this->actingAs($admin)->call('POST', 'admin/categories/9999/delete');
    }
}
