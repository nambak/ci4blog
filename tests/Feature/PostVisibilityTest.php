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
use CodeIgniter\Test\FeatureTestTrait;

/**
 * 비발행 글(draft·private)이 공개 화면에서 감춰지는지 확인한다.
 */
final class PostVisibilityTest extends CIUnitTestCase
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

    /** 발행 1 · 초안 1 · 비공개 1 을 같은 카테고리에 만든다. */
    private function seedThreeStatuses(?int $ownerId = null): int
    {
        $categories = model(CategoryModel::class);
        $categories->insert(['name' => '테스트분류']);
        $catId = $categories->getInsertID();

        $posts = model(PostModel::class);
        foreach ([
            ['공개된 글', Post::STATUS_PUBLISHED],
            ['초안 상태 글', Post::STATUS_DRAFT],
            ['숨긴 글', Post::STATUS_PRIVATE],
        ] as [$title, $status]) {
            $posts->insert([
                'user_id'     => $ownerId,
                'category_id' => $catId,
                'title'       => $title,
                'body'        => '본문',
                'status'      => $status,
            ]);
        }

        return $catId;
    }

    public function testArchiveHidesUnpublishedPosts(): void
    {
        $this->seedThreeStatuses();

        $result = $this->call('GET', 'posts');

        $result->assertStatus(200);
        $result->assertSee('공개된 글');
        $result->assertDontSee('초안 상태 글');
        $result->assertDontSee('숨긴 글');
    }

    public function testHomeHidesUnpublishedPosts(): void
    {
        $this->seedThreeStatuses();

        $result = $this->call('GET', '/');

        $result->assertStatus(200);
        $result->assertSee('공개된 글');
        $result->assertDontSee('초안 상태 글');
        $result->assertDontSee('숨긴 글');
    }

    public function testCategoryListingHidesUnpublishedPosts(): void
    {
        $this->seedThreeStatuses();
        $slug = model(CategoryModel::class)->first()->slug;

        $result = $this->call('GET', "categories/{$slug}");

        $result->assertStatus(200);
        $result->assertSee('공개된 글');
        $result->assertDontSee('초안 상태 글');
    }

    public function testSearchHidesUnpublishedPosts(): void
    {
        $this->seedThreeStatuses();

        $result = $this->call('GET', 'posts?q=글');

        $result->assertStatus(200);
        $result->assertSee('공개된 글');
        $result->assertDontSee('초안 상태 글');
    }

    public function testPublishedPostIsVisibleToGuest(): void
    {
        $this->seedThreeStatuses();
        $post = model(PostModel::class)->where('title', '공개된 글')->first();

        $result = $this->call('GET', "posts/{$post->slug}");

        $result->assertStatus(200);
        $result->assertSee('공개된 글');
    }

    public function testGuestGetsNotFoundOnDraft(): void
    {
        $this->seedThreeStatuses();
        $post = model(PostModel::class)->where('title', '초안 상태 글')->first();

        // 403 이 아니라 404 다 — 403 은 그 슬러그의 글이 존재한다는 사실을 흘린다.
        $this->expectException(PageNotFoundException::class);
        $this->call('GET', "posts/{$post->slug}");
    }

    public function testOtherUserGetsNotFoundOnDraft(): void
    {
        $owner = $this->makeUser('owner', 'owner@example.com');
        $this->seedThreeStatuses((int) $owner->id);
        $other = $this->makeUser('other', 'other@example.com');
        $post  = model(PostModel::class)->where('title', '초안 상태 글')->first();

        $this->expectException(PageNotFoundException::class);
        $this->actingAs($other)->call('GET', "posts/{$post->slug}");
    }

    public function testOwnerSeesDraftWithPreviewBanner(): void
    {
        $owner = $this->makeUser('owner', 'owner@example.com');
        $this->seedThreeStatuses((int) $owner->id);
        $post = model(PostModel::class)->where('title', '초안 상태 글')->first();

        $result = $this->actingAs($owner)->call('GET', "posts/{$post->slug}");

        $result->assertStatus(200);
        $result->assertSee('초안 상태 글');
        $result->assertSee('아직 발행되지 않았습니다');
    }

    public function testAdminSeesPrivatePostOfOtherAuthor(): void
    {
        $owner = $this->makeUser('owner', 'owner@example.com');
        $this->seedThreeStatuses((int) $owner->id);

        $admin = $this->makeUser('admin', 'admin@example.com');
        $admin->addGroup('admin');

        $post = model(PostModel::class)->where('title', '숨긴 글')->first();

        $result = $this->actingAs($admin)->call('GET', "posts/{$post->slug}");

        $result->assertStatus(200);
        $result->assertSee('숨긴 글');
    }

    public function testSuperadminSeesDraftOfOtherAuthor(): void
    {
        $owner = $this->makeUser('owner', 'owner@example.com');
        $this->seedThreeStatuses((int) $owner->id);

        // /admin 라우트 그룹이 admin·superadmin 을 같은 권한으로 다루므로,
        // is_owner_or_admin() 도 superadmin 을 통과시켜야 한다.
        $super = $this->makeUser('super', 'super@example.com');
        $super->addGroup('superadmin');

        $post = model(PostModel::class)->where('title', '초안 상태 글')->first();

        $result = $this->actingAs($super)->call('GET', "posts/{$post->slug}");

        $result->assertStatus(200);
        $result->assertSee('초안 상태 글');
    }
}
