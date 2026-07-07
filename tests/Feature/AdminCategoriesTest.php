<?php

namespace Tests\Feature;

use App\Models\CategoryModel;
use App\Models\PostModel;
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
        $result->assertSee('미분류');       // 미분류 읽기 전용 행
        $result->assertSee('카테고리', 'h1'); // 페이지 제목
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
}
