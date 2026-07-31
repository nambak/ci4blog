<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * 공개 페이지의 메타태그(#113).
 *
 * SNS 공유 미리보기와 검색 스니펫이 이 태그들에 달려 있다. 화면에 보이지 않아
 * 조용히 빠져도 아무도 모르는 종류라 테스트로 붙잡는다.
 *
 * @internal
 */
final class MetaTagsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        // View::include() 는 $saveData=true 라 뷰 데이터가 인스턴스에 쌓이고,
        // renderer 는 shared 서비스다. 리셋하지 않으면 앞 테스트의 meta 가
        // 남아 "기본값으로 떨어진다"는 검증이 거짓 통과한다(실측).
        Services::resetSingle('renderer');
    }

    /** 한글 단언은 엔티티 디코드를 거쳐야 CI(ubuntu)에서도 통과한다. */
    private function decodedBody(TestResponse $result): string
    {
        return html_entity_decode($result->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** `<meta ... content="X">` 에서 X 를 뽑는다. 없으면 null. */
    private function metaContent(string $html, string $attr, string $name): ?string
    {
        $pattern = sprintf(
            '/<meta\s+%s="%s"\s+content="([^"]*)"/',
            preg_quote($attr, '/'),
            preg_quote($name, '/')
        );

        return preg_match($pattern, $html, $m) === 1 ? $m[1] : null;
    }

    public function testHomeUsesSiteDefaults(): void
    {
        $html = $this->decodedBody($this->call('GET', '/'));

        $this->assertSame(config('Blog')->title, $this->metaContent($html, 'property', 'og:title'));
        $this->assertSame(config('Blog')->description, $this->metaContent($html, 'property', 'og:description'));
        $this->assertSame(config('Blog')->description, $this->metaContent($html, 'name', 'description'));
        $this->assertSame('website', $this->metaContent($html, 'property', 'og:type'));
        $this->assertSame(config('Blog')->title, $this->metaContent($html, 'property', 'og:site_name'));
        $this->assertSame('ko_KR', $this->metaContent($html, 'property', 'og:locale'));
    }

    /** 사이트 설명이 비어 있으면 태그가 빈 채로 나간다 — 설정 자체를 지킨다. */
    public function testSiteDescriptionIsConfigured(): void
    {
        $this->assertNotSame('', trim(config('Blog')->description));
    }

    /**
     * og:url 은 canonical 과 같아야 한다.
     *
     * apex 도메인에서도 같은 글을 서빙하므로 정본이 갈라지면 중복 콘텐츠가 된다.
     */
    public function testOgUrlMatchesCanonical(): void
    {
        $html = $this->decodedBody($this->call('GET', 'about'));

        preg_match('/<link rel="canonical" href="([^"]*)"/', $html, $m);
        $this->assertNotEmpty($m, 'canonical 링크를 찾지 못했다.');
        $this->assertSame($m[1], $this->metaContent($html, 'property', 'og:url'));
    }

    /** 이미지가 없으면 og:image 를 내지 않고 카드 종류도 summary 다. */
    public function testPageWithoutImageOmitsOgImage(): void
    {
        $html = $this->decodedBody($this->call('GET', '/'));

        $this->assertNull($this->metaContent($html, 'property', 'og:image'));
        $this->assertSame('summary', $this->metaContent($html, 'name', 'twitter:card'));
    }

    private function makeUser(): \CodeIgniter\Shield\Entities\User
    {
        $users = auth()->getProvider();
        $user  = new \CodeIgniter\Shield\Entities\User([
            'username' => 'writer',
            'email'    => 'writer@example.com',
            'password' => 'secret-password-123',
        ]);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    /** @return \App\Entities\Post */
    private function makePost(int $userId, string $title, string $body, ?string $image = null)
    {
        $posts = model(\App\Models\PostModel::class);
        $posts->insert([
            'user_id' => $userId,
            'title'   => $title,
            'body'    => $body,
            'image'   => $image,
            'status'  => \App\Entities\Post::STATUS_PUBLISHED,
        ]);

        return $posts->find($posts->getInsertID());
    }

    public function testPostDetailHasArticleOgTags(): void
    {
        $user = $this->makeUser();
        $post = $this->makePost($user->id, '오지에스 태그 시험', '본문 첫 문장이다. 두 번째 문장.', 'cover.png');

        $html = $this->decodedBody($this->call('GET', 'posts/' . $post->slug));

        $this->assertSame('article', $this->metaContent($html, 'property', 'og:type'));
        $this->assertSame('오지에스 태그 시험', $this->metaContent($html, 'property', 'og:title'));
        $this->assertStringContainsString('본문 첫 문장이다', (string) $this->metaContent($html, 'property', 'og:description'));
    }

    /** 이미지는 절대 URL 이어야 SNS 가 가져갈 수 있다. */
    public function testPostImageIsAbsoluteUrl(): void
    {
        $user = $this->makeUser();
        $post = $this->makePost($user->id, '이미지 있는 글', '본문', 'cover.png');

        $html  = $this->decodedBody($this->call('GET', 'posts/' . $post->slug));
        $image = $this->metaContent($html, 'property', 'og:image');

        $this->assertNotNull($image, 'og:image 가 있어야 한다.');
        $this->assertStringStartsWith('http', $image);
        $this->assertStringEndsWith('/uploads/cover.png', $image);
        $this->assertSame('summary_large_image', $this->metaContent($html, 'name', 'twitter:card'));
    }

    public function testPostWithoutImageOmitsOgImage(): void
    {
        $user = $this->makeUser();
        $post = $this->makePost($user->id, '이미지 없는 글', '본문');

        $html = $this->decodedBody($this->call('GET', 'posts/' . $post->slug));

        $this->assertNull($this->metaContent($html, 'property', 'og:image'));
        $this->assertSame('summary', $this->metaContent($html, 'name', 'twitter:card'));
    }

    /**
     * 설명은 검색 스니펫 길이에 맞춰 잘린다.
     *
     * 목록 카드용 기본값(80)을 그대로 쓰면 스니펫이 너무 짧아진다. 155 를
     * 넘겨 자르므로 최대 길이는 155 + 말줄임표 1자다.
     */
    public function testDescriptionIsTrimmedToSnippetLength(): void
    {
        $user = $this->makeUser();
        $post = $this->makePost($user->id, '아주 긴 글', str_repeat('가나다라마바사아자차', 40));

        $html        = $this->decodedBody($this->call('GET', 'posts/' . $post->slug));
        $description = (string) $this->metaContent($html, 'property', 'og:description');

        $this->assertSame(156, mb_strlen($description), '155자 + 말줄임표여야 한다.');
        $this->assertStringEndsWith('…', $description);
    }

    /**
     * 제목에 & 가 들어가도 이중 이스케이프되지 않는다.
     *
     * 이 설계의 핵심 방지망이다. 컨트롤러가 esc() 한 값을 넘기거나 제목을
     * renderSection 에서 재사용하면 `&amp;amp;` 가 나온다.
     */
    public function testTitleIsNotDoubleEscaped(): void
    {
        $user = $this->makeUser();
        $post = $this->makePost($user->id, '정규식 & 패턴', '본문');

        // 여기서는 디코드하지 않은 원본 HTML 을 본다 — 이스케이프 상태 자체가 검증 대상이다.
        $html = $this->call('GET', 'posts/' . $post->slug)->getBody();

        $this->assertStringNotContainsString('&amp;amp;', $html, '이중 이스케이프됐다.');
        $this->assertStringContainsString('content="정규식 &amp; 패턴"', $html);
    }

    public function testPostListHasOwnTitle(): void
    {
        $html = $this->decodedBody($this->call('GET', 'posts'));

        $this->assertSame('글 목록', $this->metaContent($html, 'property', 'og:title'));
        // 설명은 사이트 기본값을 그대로 쓴다.
        $this->assertSame(config('Blog')->description, $this->metaContent($html, 'property', 'og:description'));
    }

    public function testCategoryPageHasCategoryTitle(): void
    {
        $categories = model(\App\Models\CategoryModel::class);
        $categories->insert(['name' => '회고', 'slug' => 'retro']);

        $html = $this->decodedBody($this->call('GET', 'categories/retro'));

        $this->assertSame('회고 글', $this->metaContent($html, 'property', 'og:title'));
    }

    public function testAboutHasOwnTitle(): void
    {
        $html = $this->decodedBody($this->call('GET', 'about'));

        $this->assertSame('소개', $this->metaContent($html, 'property', 'og:title'));
    }
}
