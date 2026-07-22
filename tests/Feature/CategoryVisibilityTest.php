<?php

namespace Tests\Feature;

use App\Entities\Post;
use App\Models\CategoryModel;
use App\Models\CommentModel;
use App\Models\CommentReportModel;
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

        // aria-label(속성)을 정규식으로 봐야 하므로 디코딩한다. TestResponse 는 본문을
        // DOMDocument 로 돌리는데 비 ASCII 를 숫자 엔티티로 바꿀지가 libxml 버전에 달려
        // 있어, 디코딩하지 않으면 CI(ubuntu)에서만 깨진다(AdminPostsTest 와 같은 함정).
        $body = html_entity_decode(
            $this->actingAs($admin)->call('GET', 'admin/categories')->getBody(),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        // 상태는 글자로 보여 준다(아이콘에 겹쳐 두지 않는다).
        $this->assertStringContainsString('공개분류', $body);
        $this->assertStringContainsString('숨김분류', $body);

        // 수정·삭제도 각각 이름이 붙은 라벨을 갖는다.
        $this->assertStringContainsString('공개분류 수정', $body);
        $this->assertStringContainsString('공개분류 삭제', $body);

        // eye-off(눈 위의 사선)는 숨김 카테고리 버튼에만 붙는다.
        //
        // 개수만 세면 안 된다 — 픽스처가 공개 1·숨김 1 이라 조건을 반전해도 사선은
        // 여전히 한 번 나온다(실제로 뮤테이션이 통과했다). 어느 버튼에 붙었는지 봐야 한다.
        $hiddenButton  = $this->buttonBlock($body, '숨김분류 공개하기');
        $visibleButton = $this->buttonBlock($body, '공개분류 숨기기');

        $this->assertStringContainsString('M3 3l18 18', $hiddenButton, '숨김 카테고리는 eye-off 여야 한다.');
        $this->assertStringNotContainsString('M3 3l18 18', $visibleButton, '공개 카테고리는 사선 없는 눈이어야 한다.');
    }

    /**
     * aria-label 로 버튼 하나를 찾아 그 <button>…</button> 조각만 돌려준다.
     * 아이콘이 "어느 버튼에" 들어갔는지 봐야 할 때 쓴다.
     */
    private function buttonBlock(string $body, string $label): string
    {
        $pattern = '/<button[^>]*aria-label="' . preg_quote($label, '/') . '".*?<\/button>/s';

        $this->assertMatchesRegularExpression($pattern, $body, "버튼을 찾지 못했다: {$label}");
        preg_match($pattern, $body, $matches);

        return $matches[0];
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

    /**
     * 수정 폼은 숨김 카테고리라도 "이 글이 지금 속한" 항목을 선택된 채로 보여 줘야 한다.
     *
     * 폼 셀렉트가 공개 메뉴(menu())를 그대로 쓰면 숨김 항목이 목록에 없어 아무 option 도
     * selected 가 되지 않는다. 그러면 브라우저는 첫 항목("— 카테고리 없음 —", value="")을
     * 제출하므로, 제목만 고쳐 저장해도 글이 조용히 미분류로 옮겨진다.
     */
    public function testEditFormKeepsHiddenCategorySelected(): void
    {
        $owner = $this->makeUser('writer', 'writer@example.com');
        $ids   = $this->seedCategories((int) $owner->id);
        $post  = model(PostModel::class)->where('title', '숨김분류 글')->first();

        $body = html_entity_decode(
            $this->actingAs($owner)->call('GET', "posts/{$post->id}/edit")->getBody(),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $this->assertMatchesRegularExpression(
            '/<option value="' . $ids['hidden'] . '" selected>/',
            $body,
            '숨김 카테고리가 선택된 채로 렌더되지 않았다 — 저장 시 미분류로 밀린다.'
        );
    }

    /** 폼의 숨김 항목은 "(숨김)"으로 표시한다 — 고르면 글이 공개 화면에서 빠진다는 신호. */
    public function testFormMarksHiddenCategoryWithLabel(): void
    {
        $owner = $this->makeUser('writer', 'writer@example.com');
        $this->seedCategories((int) $owner->id);

        // 본문을 문자열로 직접 검사하므로 디코딩이 필요하다 — libxml 버전에 따라 비 ASCII 가
        // 숫자 엔티티(&#49704;…)로 올라오며, 그러면 로컬은 통과하고 CI(ubuntu)만 깨진다.
        $body = html_entity_decode(
            $this->actingAs($owner)->call('GET', 'posts/new')->getBody(),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $this->assertStringContainsString('숨김분류 (숨김)', $body);
        // 공개 카테고리에까지 꼬리표가 붙으면 안 된다.
        $this->assertStringNotContainsString('공개분류 (숨김)', $body);
    }

    /**
     * 관리자 글 목록의 일괄 이동 셀렉트에도 숨김 카테고리가 있어야 한다.
     *
     * 없으면 관리자는 글을 숨김 카테고리로 옮길 방법이 없다 — 이슈 #67 의
     * "관리자에겐 계속 노출"과 어긋난다.
     */
    public function testAdminBulkMoveSelectIncludesHiddenCategory(): void
    {
        $this->seedCategories();
        $admin = $this->makeAdmin();

        $body = html_entity_decode(
            $this->actingAs($admin)->call('GET', 'admin/posts')->getBody(),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        // 셀렉트 안쪽만 본다 — 목록 표의 카테고리 칸에 이름이 있는 것으로 통과하면 안 된다.
        preg_match('/<select name="category_id".*?<\/select>/s', $body, $matches);
        $this->assertNotEmpty($matches, '일괄 이동 셀렉트를 찾지 못했다.');

        $this->assertStringContainsString('숨김분류', $matches[0]);
        $this->assertStringContainsString('공개분류', $matches[0]);
    }

    /**
     * 숨김 카테고리 글에는 댓글을 달 수 없다.
     *
     * #67 이 상세(Posts::assertViewable)만 2단으로 바꾸고 Comments::store() 는
     * isPublished() 만 보게 남겨 뒀다. 그래서 상세가 404 인 글에 글 id 만 알면
     * 댓글이 달렸고, 성공 리다이렉트의 Location 으로 슬러그까지 샜다.
     */
    public function testCannotCommentOnPostInHiddenCategory(): void
    {
        $this->seedCategories();
        $post     = model(PostModel::class)->where('title', '숨김분류 글')->first();
        $stranger = $this->makeUser('stranger', 'stranger@example.com');
        $comments = model(CommentModel::class);

        try {
            $this->actingAs($stranger)->call('POST', "posts/{$post->id}/comments", ['body' => '숨김 글에 남기는 댓글']);
            $this->fail('숨김 카테고리 글에는 댓글을 달 수 없어야 한다.');
        } catch (PageNotFoundException) {
            // 기대한 경로.
        }

        $this->assertSame(
            0,
            $comments->where('post_id', $post->id)->countAllResults(),
            '404 로 막혔다면 댓글이 저장돼 있으면 안 된다.'
        );
    }

    /** 숨김 카테고리 글의 댓글은 신고할 수 없다 — store() 와 같은 이유. */
    public function testCannotReportCommentOnPostInHiddenCategory(): void
    {
        $this->seedCategories();
        $post   = model(PostModel::class)->where('title', '숨김분류 글')->first();
        $author = $this->makeUser('author', 'author@example.com');

        $comments = model(CommentModel::class);
        $comments->insert(['post_id' => $post->id, 'user_id' => $author->id, 'body' => '남의 댓글']);
        $commentId = $comments->getInsertID();

        $reporter = $this->makeUser('reporter', 'reporter@example.com');
        $reports  = model(CommentReportModel::class);

        try {
            $this->actingAs($reporter)->call('POST', "comments/{$commentId}/report", ['reason' => 'spam']);
            $this->fail('숨김 카테고리 글의 댓글은 신고할 수 없어야 한다.');
        } catch (PageNotFoundException) {
            // 기대한 경로.
        }

        $this->assertSame(
            0,
            $reports->where('comment_id', $commentId)->countAllResults(),
            '404 로 막혔다면 신고가 저장돼 있으면 안 된다.'
        );
    }
}
