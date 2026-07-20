<?php

namespace Tests\Feature;

use App\Entities\Post;
use App\Models\CategoryModel;
use App\Models\PostModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\WithCsrf;

/**
 * 카테고리 공개/숨김(is_visible)에 대한 Feature 테스트. (#67)
 *
 * 숨김의 의미는 "카테고리 단위 비공개"다 — 메뉴에서만 사라지는 게 아니라
 * 그 카테고리의 글도 공개 화면에서 함께 빠진다. 미분류(category_id = NULL)는
 * 카테고리 레코드가 없으므로 항상 공개다.
 */
final class CategoryVisibilityTest extends CIUnitTestCase
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

    /**
     * 공개 카테고리 1 · 숨김 카테고리 1 을 만들고 각각 발행글을 하나씩 넣는다.
     * 미분류 발행글도 하나 만든다(숨김이 미분류까지 끌고 가지 않는지 보려고).
     *
     * @return array{visible:int, hidden:int}
     */
    private function seedCategories(?int $ownerId = null): array
    {
        $categories = model(CategoryModel::class);

        $categories->insert(['name' => '공개분류']);
        $visibleId = $categories->getInsertID();

        $categories->insert(['name' => '숨김분류', 'is_visible' => 0]);
        $hiddenId = $categories->getInsertID();

        $posts = model(PostModel::class);
        foreach ([
            ['공개분류 글', $visibleId],
            ['숨김분류 글', $hiddenId],
            ['미분류 글', null],
        ] as [$title, $catId]) {
            $posts->insert([
                'user_id'     => $ownerId,
                'category_id' => $catId,
                'title'       => $title,
                'body'        => '본문',
                'status'      => Post::STATUS_PUBLISHED,
            ]);
        }

        return ['visible' => $visibleId, 'hidden' => $hiddenId];
    }

    /**
     * 공개 메뉴에는 숨김 카테고리가 없어야 한다.
     */
    public function testHiddenCategoryIsExcludedFromPublicMenu(): void
    {
        $this->seedCategories();

        $names = array_map(
            static fn ($category) => $category->name,
            model(CategoryModel::class)->menu()
        );

        $this->assertContains('공개분류', $names);
        $this->assertNotContains('숨김분류', $names);
    }

    /** 글 목록(/posts)에서 숨김 카테고리의 글이 빠진다. */
    public function testHiddenCategoryPostsAreExcludedFromPostList(): void
    {
        $this->seedCategories();

        $result = $this->call('GET', 'posts');

        $result->assertStatus(200);
        $result->assertSee('공개분류 글');
        $result->assertDontSee('숨김분류 글');
    }

    /** 홈에서도 빠진다. */
    public function testHiddenCategoryPostsAreExcludedFromHome(): void
    {
        $this->seedCategories();

        $result = $this->call('GET', '/');

        $result->assertStatus(200);
        $result->assertSee('공개분류 글');
        $result->assertDontSee('숨김분류 글');
    }

    /** 검색 결과에서도 빠진다 — 숨긴 글이 검색으로 새는 건 숨김의 의미를 무너뜨린다. */
    public function testHiddenCategoryPostsAreExcludedFromSearch(): void
    {
        $this->seedCategories();

        $result = $this->call('GET', 'posts?q=글');

        $result->assertStatus(200);
        $result->assertSee('공개분류 글');
        $result->assertDontSee('숨김분류 글');
    }

    /**
     * 미분류(category_id = NULL) 글은 계속 보인다.
     *
     * 이 설계에서 가장 위험한 경계다. published() 의 서브쿼리에서 IS NULL 분기가
     * 빠지면 미분류 글이 통째로 사라지는데, 위 세 테스트는 전부 통과한다
     * (그 테스트들이 보는 '공개분류 글' 은 카테고리가 있으므로).
     */
    public function testUncategorizedPostsRemainVisible(): void
    {
        $this->seedCategories();

        $this->call('GET', 'posts')->assertSee('미분류 글');
        $this->call('GET', '/')->assertSee('미분류 글');
    }

    /**
     * 숨김 카테고리 페이지는 404.
     *
     * 403 이 아니라 404 인 이유는 비발행 글과 같다 — 403 은 그 슬러그가 존재한다는
     * 사실 자체를 흘린다.
     */
    public function testHiddenCategoryPageReturnsNotFound(): void
    {
        $ids  = $this->seedCategories();
        $slug = model(CategoryModel::class)->find($ids['hidden'])->slug;

        $this->expectException(PageNotFoundException::class);
        $this->call('GET', "categories/{$slug}");
    }

    /** 숨김 카테고리에 속한 글의 상세는 게스트에게 404. */
    public function testHiddenCategoryPostIsNotFoundForGuest(): void
    {
        $this->seedCategories();
        $post = model(PostModel::class)->where('title', '숨김분류 글')->first();

        $this->expectException(PageNotFoundException::class);
        $this->call('GET', "posts/{$post->slug}");
    }

    /**
     * 같은 글을 관리자는 볼 수 있다(비발행 글의 미리보기와 같은 규칙).
     * 숨김은 "공개 화면에서 감춘다"는 뜻이지 관리자에게도 잠근다는 뜻이 아니다.
     */
    public function testAdminCanStillViewHiddenCategoryPost(): void
    {
        $this->seedCategories();
        $admin = $this->makeAdmin();
        $post  = model(PostModel::class)->where('title', '숨김분류 글')->first();

        $result = $this->actingAs($admin)->call('GET', "posts/{$post->slug}");

        $result->assertStatus(200);
        $result->assertSee('숨김분류 글');
    }

    /** 관리 목록에는 숨김 카테고리도 계속 보인다 — 안 보이면 다시 공개로 되돌릴 수 없다. */
    public function testAdminListShowsHiddenCategory(): void
    {
        $this->seedCategories();
        $admin = $this->makeAdmin();

        $result = $this->actingAs($admin)->call('GET', 'admin/categories');

        $result->assertStatus(200);
        $result->assertSee('공개분류');
        $result->assertSee('숨김분류');
    }

    /**
     * 관리 목록이 상태 글자와 아이콘 버튼 3종을 렌더한다.
     *
     * 작업 버튼을 아이콘으로 바꾸면서 눈에 보이는 글자가 사라졌다. aria-label 이
     * 빠지면 스크린리더 사용자에게는 정체불명의 버튼 세 개만 남는데, 화면을 보고
     * 하는 확인으로는 그 회귀를 절대 알아챌 수 없다.
     */
    public function testAdminListRendersAccessibleActionIcons(): void
    {
        $this->seedCategories();
        $admin = $this->makeAdmin();

        $body = $this->actingAs($admin)->call('GET', 'admin/categories')->getBody();

        // 상태는 글자로 보여 준다(아이콘에 겹쳐 두지 않는다).
        $this->assertStringContainsString('공개분류', $body);
        $this->assertStringContainsString('숨김분류', $body);

        // 공개 카테고리는 "숨기기", 숨김 카테고리는 "공개하기" 로 동작을 알린다.
        $this->assertStringContainsString('공개분류 숨기기', $body);
        $this->assertStringContainsString('숨김분류 공개하기', $body);

        // 수정·삭제도 각각 이름이 붙은 라벨을 갖는다.
        $this->assertStringContainsString('공개분류 수정', $body);
        $this->assertStringContainsString('공개분류 삭제', $body);
    }

    /** 토글이 공개↔숨김을 뒤집는다. */
    public function testToggleFlipsVisibility(): void
    {
        $ids   = $this->seedCategories();
        $admin = $this->makeAdmin();

        // 공개 → 숨김
        $this->actingAs($admin)->call('POST', "admin/categories/{$ids['visible']}/visibility")
            ->assertRedirect();
        $this->assertFalse(model(CategoryModel::class)->find($ids['visible'])->is_visible);

        // 숨김 → 공개
        $this->actingAs($admin)->call('POST', "admin/categories/{$ids['hidden']}/visibility")
            ->assertRedirect();
        $this->assertTrue(model(CategoryModel::class)->find($ids['hidden'])->is_visible);
    }

    /** 일반 사용자는 토글할 수 없다(admin 라우트 그룹 필터). */
    public function testNormalUserCannotToggle(): void
    {
        $ids  = $this->seedCategories();
        $user = $this->makeUser('member', 'member@example.com');

        $this->actingAs($user)->call('POST', "admin/categories/{$ids['visible']}/visibility")
            ->assertRedirect();

        // 값이 그대로여야 한다 — 리다이렉트만 보고 넘어가면 변경됐는지 알 수 없다.
        $this->assertTrue(model(CategoryModel::class)->find($ids['visible'])->is_visible);
    }
}
