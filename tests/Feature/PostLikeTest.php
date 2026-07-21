<?php

namespace Tests\Feature;

use App\Entities\Post;
use App\Models\CategoryModel;
use App\Models\CommentModel;
use App\Models\PostModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\WithCsrf;

/**
 * 게시글 좋아요(#64).
 *
 * POST 를 호출하므로 FeatureTestTrait 대신 WithCsrf 를 쓴다(#73 로 CSRF 가 전역이다).
 */
final class PostLikeTest extends CIUnitTestCase
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

    private function makePost(int $userId, array $overrides = []): Post
    {
        $posts = model(PostModel::class);
        $posts->insert(array_merge([
            'user_id' => $userId,
            'title'   => '좋아요 대상 글',
            'body'    => '본문',
            'status'  => Post::STATUS_PUBLISHED,
        ], $overrides));

        return $posts->find($posts->getInsertID());
    }

    private function makeCategory(string $name, bool $visible): int
    {
        $categories = model(CategoryModel::class);
        $categories->insert(['name' => $name, 'slug' => url_title($name, '-', true), 'is_visible' => $visible ? 1 : 0]);

        return $categories->getInsertID();
    }

    /**
     * 좋아요 수를 DB 에서 직접 센다.
     *
     * 모델을 거치지 않는 건 의도적이다 — 모델 메서드가 잘못돼도 테스트는 실제 저장 상태를 본다.
     */
    private function likeCount(int $postId): int
    {
        return db_connect()->table('post_likes')->where('post_id', $postId)->countAllResults();
    }

    public function testLoggedInUserCanLikePost(): void
    {
        $author = $this->makeUser('author', 'author@example.com');
        $liker  = $this->makeUser('liker', 'liker@example.com');
        $post   = $this->makePost((int) $author->id);

        $this->actingAs($liker)->call('POST', "posts/{$post->id}/like");

        $this->assertSame(1, $this->likeCount((int) $post->id));
        $this->seeInDatabase('post_likes', ['post_id' => $post->id, 'user_id' => $liker->id]);
    }

    /** 같은 사용자가 다시 누르면 취소된다(토글). */
    public function testLikingAgainCancelsTheLike(): void
    {
        $author = $this->makeUser('author', 'author@example.com');
        $liker  = $this->makeUser('liker', 'liker@example.com');
        $post   = $this->makePost((int) $author->id);

        $this->actingAs($liker)->call('POST', "posts/{$post->id}/like");
        $this->actingAs($liker)->call('POST', "posts/{$post->id}/like");

        $this->assertSame(0, $this->likeCount((int) $post->id));
        $this->dontSeeInDatabase('post_likes', ['post_id' => $post->id, 'user_id' => $liker->id]);
    }

    /** 사용자마다 한 번씩 눌러 카운트가 쌓인다 — 취소는 누른 사람 것만 지운다. */
    public function testDifferentUsersEachAddOneLike(): void
    {
        $author = $this->makeUser('author', 'author@example.com');
        $first  = $this->makeUser('first', 'first@example.com');
        $second = $this->makeUser('second', 'second@example.com');
        $post   = $this->makePost((int) $author->id);

        $this->actingAs($first)->call('POST', "posts/{$post->id}/like");
        $this->actingAs($second)->call('POST', "posts/{$post->id}/like");

        $this->assertSame(2, $this->likeCount((int) $post->id));
    }

    /**
     * 비로그인은 로그인 페이지로 보내지고 좋아요가 저장되지 않는다.
     *
     * 리다이렉트만 단언하면 실제로 저장됐는지 알 수 없으므로 카운트 불변까지 본다.
     */
    public function testGuestIsRedirectedAndNothingIsStored(): void
    {
        $author = $this->makeUser('author', 'author@example.com');
        $post   = $this->makePost((int) $author->id);

        $this->call('POST', "posts/{$post->id}/like")->assertRedirect();

        $this->assertSame(0, $this->likeCount((int) $post->id));
    }

    /** 없는 글은 404. */
    public function testLikingMissingPostIsNotFound(): void
    {
        $liker = $this->makeUser('liker', 'liker@example.com');

        $this->expectException(PageNotFoundException::class);
        $this->actingAs($liker)->call('POST', 'posts/9999/like');
    }

    /**
     * 비발행 글(초안)은 상세와 같은 규칙으로 막는다.
     *
     * 상세가 404 인데 좋아요만 열려 있으면, 슬러그·id 를 아는 사람이 응답 차이로
     * 글의 존재를 확인할 수 있다(#79 에서 CodeRabbit 이 Major 로 잡았던 것과 같은 자리).
     */
    public function testOtherUserCannotLikeDraftPost(): void
    {
        $author = $this->makeUser('author', 'author@example.com');
        $liker  = $this->makeUser('liker', 'liker@example.com');
        $post   = $this->makePost((int) $author->id, ['status' => Post::STATUS_DRAFT]);

        try {
            $this->actingAs($liker)->call('POST', "posts/{$post->id}/like");
            $this->fail('초안 글에 좋아요가 허용됐다 — 상세는 404 인데 좋아요만 열려 있다.');
        } catch (PageNotFoundException) {
            // 기대한 경로.
        }

        // 404 만 보고 넘어가지 않는다 — 예외 전에 저장돼 버렸을 수 있다.
        $this->assertSame(0, $this->likeCount((int) $post->id));
    }

    /** 작성자 본인은 자기 초안 글에 좋아요할 수 있다(상세를 미리보기로 볼 수 있는 것과 같은 규칙). */
    public function testAuthorCanLikeOwnDraftPost(): void
    {
        $author = $this->makeUser('author', 'author@example.com');
        $post   = $this->makePost((int) $author->id, ['status' => Post::STATUS_DRAFT]);

        $this->actingAs($author)->call('POST', "posts/{$post->id}/like");

        $this->assertSame(1, $this->likeCount((int) $post->id));
    }

    /**
     * 숨김 카테고리(#67)에 속한 글도 상세와 같은 규칙으로 막는다.
     *
     * 카테고리를 숨긴다는 건 그 글들을 공개 화면에서 뺀다는 뜻이다. 상세는 404 인데
     * 좋아요만 열려 있으면 응답 차이로 글의 존재가 새고, 숨긴 글에 좋아요가 쌓인다.
     */
    public function testOtherUserCannotLikePostInHiddenCategory(): void
    {
        $author   = $this->makeUser('author', 'author@example.com');
        $liker    = $this->makeUser('liker', 'liker@example.com');
        $hiddenId = $this->makeCategory('숨김분류', false);
        $post     = $this->makePost((int) $author->id, ['category_id' => $hiddenId]);

        try {
            $this->actingAs($liker)->call('POST', "posts/{$post->id}/like");
            $this->fail('숨김 카테고리 글에 좋아요가 허용됐다 — 상세는 404 인데 좋아요만 열려 있다.');
        } catch (PageNotFoundException) {
            // 기대한 경로.
        }

        $this->assertSame(0, $this->likeCount((int) $post->id));
    }

    /** 공개 카테고리 글은 그대로 좋아요된다 — 위 가드가 카테고리 있는 글 전부를 막으면 안 된다. */
    public function testPostInVisibleCategoryCanBeLiked(): void
    {
        $author    = $this->makeUser('author', 'author@example.com');
        $liker     = $this->makeUser('liker', 'liker@example.com');
        $visibleId = $this->makeCategory('공개분류', true);
        $post      = $this->makePost((int) $author->id, ['category_id' => $visibleId]);

        $this->actingAs($liker)->call('POST', "posts/{$post->id}/like");

        $this->assertSame(1, $this->likeCount((int) $post->id));
    }

    /**
     * 상세의 좋아요 영역만 잘라낸다.
     *
     * 본문 아무 데나 숫자가 있어서 통과하는 위양성을 막는다.
     */
    private function likeSection(string $body): string
    {
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match('/<div[^>]*id="like".*?<\/div>/s', $body, $matches);
        $this->assertNotEmpty($matches, '상세에서 좋아요 영역(id="like")을 찾지 못했다.');

        return $matches[0];
    }

    /** 글 상세에 좋아요 수가 렌더된다. */
    public function testShowRendersLikeCount(): void
    {
        $author = $this->makeUser('author', 'author@example.com');
        $first  = $this->makeUser('first', 'first@example.com');
        $second = $this->makeUser('second', 'second@example.com');
        $post   = $this->makePost((int) $author->id);

        $this->actingAs($first)->call('POST', "posts/{$post->id}/like");
        $this->actingAs($second)->call('POST', "posts/{$post->id}/like");

        $section = $this->likeSection($this->call('GET', "posts/{$post->slug}")->getBody());

        // 목업(Post 의 engagement bar)은 문장이 아니라 하트 옆 숫자만 보여 준다.
        $this->assertStringContainsString('<span class="like-count">2</span>', $section);
        // 하드코딩된 0 이 아니라 실제 카운트여야 한다.
        $this->assertStringNotContainsString('<span class="like-count">0</span>', $section);
    }

    /**
     * 이미 누른 사용자에게는 하트가 채워지고 취소 라벨이 붙는다.
     *
     * 목업은 아이콘만 두므로 화면에 글자가 없다 — 상태를 색으로만 알리면 스크린리더가
     * 읽지 못하니 aria-label 과 aria-pressed 가 그 역할을 대신해야 한다.
     */
    public function testLikeButtonShowsCancelStateWhenAlreadyLiked(): void
    {
        $author = $this->makeUser('author', 'author@example.com');
        $liker  = $this->makeUser('liker', 'liker@example.com');
        $post   = $this->makePost((int) $author->id);

        $this->actingAs($liker)->call('POST', "posts/{$post->id}/like");
        $section = $this->likeSection($this->actingAs($liker)->call('GET', "posts/{$post->slug}")->getBody());

        $this->assertStringContainsString('aria-label="좋아요 취소"', $section);
        $this->assertStringContainsString('aria-pressed="true"', $section);
        // 색(is-liked)만이 아니라 하트를 채워서도 알린다 — 색각 이상 사용자를 위해.
        $this->assertStringContainsString('is-liked', $section);
        $this->assertStringContainsString('fill="currentColor"', $section);
    }

    /** 아직 안 누른 사용자에게는 빈 하트와 좋아요 라벨이 보인다. */
    public function testLikeButtonShowsLikeStateWhenNotLiked(): void
    {
        $author = $this->makeUser('author', 'author@example.com');
        $liker  = $this->makeUser('liker', 'liker@example.com');
        $post   = $this->makePost((int) $author->id);

        $section = $this->likeSection($this->actingAs($liker)->call('GET', "posts/{$post->slug}")->getBody());

        $this->assertStringContainsString('aria-label="좋아요"', $section);
        $this->assertStringContainsString('aria-pressed="false"', $section);
        $this->assertStringNotContainsString('is-liked', $section);
        $this->assertStringNotContainsString('fill="currentColor"', $section);
    }

    /**
     * 목업의 engagement bar 는 좋아요 옆에 댓글 수를 함께 보여 주고, 누르면 댓글 영역으로 간다.
     *
     * 링크 대상(id="comments")이 실제로 있는지까지 본다 — 앵커만 걸고 대상이 없으면 아무 데도 안 간다.
     */
    public function testEngagementBarShowsCommentCountLinkedToComments(): void
    {
        $author = $this->makeUser('author', 'author@example.com');
        $post   = $this->makePost((int) $author->id);
        $comments = model(CommentModel::class);
        $comments->insert(['post_id' => $post->id, 'user_id' => $author->id, 'body' => '첫 댓글']);
        $comments->insert(['post_id' => $post->id, 'user_id' => $author->id, 'body' => '둘째 댓글']);

        $body    = $this->call('GET', "posts/{$post->slug}")->getBody();
        $section = $this->likeSection($body);

        $this->assertStringContainsString('<span class="comment-count">2</span>', $section);
        // 하드코딩된 0 이 아니라 실제 개수여야 한다.
        $this->assertStringNotContainsString('<span class="comment-count">0</span>', $section);
        $this->assertStringContainsString('href="#comments"', $section);
        $this->assertStringContainsString('id="comments"', $body, '앵커 대상인 댓글 영역이 없다.');
    }

    /** 비로그인에게는 폼 대신 로그인 유도만 보인다(댓글 영역과 같은 패턴). */
    public function testGuestSeesLoginPromptInsteadOfLikeForm(): void
    {
        $author = $this->makeUser('author', 'author@example.com');
        $post   = $this->makePost((int) $author->id);

        $section = $this->likeSection($this->call('GET', "posts/{$post->slug}")->getBody());

        $this->assertStringNotContainsString('<form', $section);
        $this->assertStringContainsString(site_url('login'), $section);
    }

    /** 자기 글 좋아요는 허용한다(개인 블로그라 막을 이유가 없다 — 스펙 결정 5). */
    public function testAuthorCanLikeOwnPost(): void
    {
        $author = $this->makeUser('author', 'author@example.com');
        $post   = $this->makePost((int) $author->id);

        $this->actingAs($author)->call('POST', "posts/{$post->id}/like");

        $this->assertSame(1, $this->likeCount((int) $post->id));
    }
}
