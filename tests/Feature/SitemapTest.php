<?php

namespace Tests\Feature;

use App\Entities\Post;
use App\Libraries\SitemapXml;
use App\Models\CategoryModel;
use App\Models\PostModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * GET /sitemap.xml 엔드포인트. (#124)
 *
 * 무엇이 실리는가(쿼리 규칙)는 SitemapQueryTest 가 본다. 여기서는 응답 형식과
 * 배선 — 라우트·Content-Type·캐시 헤더·URL 인코딩 — 을 확인한다.
 *
 * GET 전용이라 WithCsrf 가 아니라 FeatureTestTrait 를 쓴다(Security::verify 는
 * POST/PUT/DELETE/PATCH 만 검증한다).
 *
 * @internal
 */
final class SitemapTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    private function seed(): void
    {
        $categories = model(CategoryModel::class);
        $categories->insert(['name' => '공개분류']);
        $visibleId = $categories->getInsertID();

        $posts = model(PostModel::class);

        foreach ([
            ['한글 제목 글', Post::STATUS_PUBLISHED],
            ['임시저장 글', Post::STATUS_DRAFT],
        ] as [$title, $status]) {
            $posts->insert([
                'user_id'     => null,
                'category_id' => $visibleId,
                'title'       => $title,
                'body'        => '본문',
                'status'      => $status,
            ]);
        }
    }

    /**
     * XML 원문을 돌려준다.
     *
     * ⚠️ TestResponse::getBody() 를 쓰면 안 된다 — __call 이 DOMParser::getBody()
     * 로 넘겨 본문을 <!DOCTYPE html …> 로 감싼다. 반드시 response() 를 거친다.
     */
    private function sitemapBody(): string
    {
        return $this->call('GET', 'sitemap.xml')->response()->getBody();
    }

    /**
     * 사이트 루트 URL(끝 슬래시 없음).
     *
     * 기대값을 site_url() 로 만들지 않는다 — 컨트롤러와 같은 함수를 쓰면 인코딩이
     * 통째로 빠져도 양쪽이 함께 틀려 통과한다. 게다가 site_url() 은 내부 parse_url()
     * 때문에 macOS 에서 한글 바이트를 '_' 로 뭉갠다(link_helper.php 의 absolute_url() 주석 참조).
     */
    private function baseUrl(): string
    {
        return rtrim(base_url(), '/');
    }

    /**
     * loc 이 $loc 인 <url> 블록만 잘라 돌려준다.
     *
     * 특정 URL 에 무엇이 붙었는지 봐야 할 때 쓴다 — 문서 전체를 문자열로 보면
     * "어딘가에 lastmod 가 있다" 로 통과해 버린다(CategoryVisibilityTest 의
     * buttonBlock() 과 같은 이유). SimpleXML 을 쓰지 않는 이유는 기본
     * 네임스페이스가 선언된 문서에서 자식 접근 방식이 PHP 버전에 따라
     * 달라져 테스트가 흔들리기 때문이다.
     */
    private function urlBlock(string $body, string $loc): string
    {
        $pattern = '#<url>\s*<loc>' . preg_quote($loc, '#') . '</loc>.*?</url>#s';

        $this->assertMatchesRegularExpression($pattern, $body, "URL 항목을 찾지 못했다: {$loc}");
        preg_match($pattern, $body, $matches);

        return $matches[0];
    }

    /** 라우트가 물리고 200 + XML Content-Type 으로 응답한다. */
    public function testRespondsWithXmlContentType(): void
    {
        $this->seed();

        $result = $this->call('GET', 'sitemap.xml');

        $result->assertStatus(200);
        $this->assertStringContainsString(
            'application/xml',
            $result->response()->getHeaderLine('Content-Type')
        );
    }

    /** 본문이 sitemaps.org 0.9 규격의 XML 이다. */
    public function testBodyIsValidSitemapXml(): void
    {
        $this->seed();

        $parsed = simplexml_load_string($this->sitemapBody());

        $this->assertNotFalse($parsed, 'sitemap 본문이 XML 로 파싱되지 않는다.');
        $this->assertSame('urlset', $parsed->getName());
        $this->assertContains(SitemapXml::NAMESPACE_URI, $parsed->getDocNamespaces());
    }

    /** 정적 공개 URL 3개가 들어 있다. */
    public function testIncludesStaticPublicUrls(): void
    {
        $this->seed();

        $body = $this->sitemapBody();

        $base = $this->baseUrl();

        $this->assertStringContainsString('<loc>' . $base . '/</loc>', $body);
        $this->assertStringContainsString('<loc>' . $base . '/posts</loc>', $body);
        $this->assertStringContainsString('<loc>' . $base . '/about</loc>', $body);
    }

    /**
     * 한글 slug 가 퍼센트 인코딩된 형태로 들어간다.
     *
     * site_url() 로 기대값을 만들면 양쪽이 같은 함수를 쓰므로 인코딩이 통째로
     * 빠져도 통과한다. 그래서 rawurlencode 로 기대값을 직접 만들고, 날 한글이
     * 본문에 없다는 것까지 확인한다.
     */
    public function testKoreanSlugIsPercentEncoded(): void
    {
        $this->seed();

        $post = model(PostModel::class)->where('title', '한글 제목 글')->first();

        // 전제를 먼저 고정한다 — slug 가 로마자로 바뀌는 구현이면 이 테스트는 무의미해진다.
        $this->assertMatchesRegularExpression('/[가-힣]/u', $post->slug, 'slug 에 한글이 남아 있어야 하는 전제가 깨졌다.');

        $body = $this->sitemapBody();

        $this->assertStringContainsString(
            '<loc>' . $this->baseUrl() . '/posts/' . rawurlencode($post->slug) . '</loc>',
            $body
        );
        $this->assertStringNotContainsString($post->slug, $body, '인코딩되지 않은 한글 slug 가 그대로 나갔다.');
    }

    /** 발행글은 실리고 임시저장 글은 실리지 않는다(엔드포인트 차원 확인). */
    public function testIncludesPublishedAndExcludesDraft(): void
    {
        $this->seed();

        $posts     = model(PostModel::class);
        $published = $posts->where('title', '한글 제목 글')->first();
        $draft     = $posts->where('title', '임시저장 글')->first();

        $body = $this->sitemapBody();

        $this->assertStringContainsString('<loc>' . $this->baseUrl() . '/posts/' . rawurlencode($published->slug) . '</loc>', $body);
        $this->assertStringNotContainsString('<loc>' . $this->baseUrl() . '/posts/' . rawurlencode($draft->slug) . '</loc>', $body);
    }

    /** 발행글이 있는 공개 카테고리 URL 이 들어 있다. */
    public function testIncludesCategoryUrl(): void
    {
        $this->seed();

        $slug = model(CategoryModel::class)->where('name', '공개분류')->first()->slug;

        $this->assertStringContainsString(
            '<loc>' . $this->baseUrl() . '/categories/' . rawurlencode($slug) . '</loc>',
            $this->sitemapBody()
        );
    }

    /**
     * /about 의 lastmod 는 config 에 적어 둔 날짜에서 온다.
     *
     * 예전에는 근거가 없어 비워 두었다. 본문을 실제로 고친 시점을 Blog 설정에
     * 명시하게 되면서 근거가 생겼다.
     *
     * 파일 mtime 을 쓰지 않는 것이 중요하다. 배포는 git pull 이라 서버를 다시
     * 세우기만 해도 mtime 이 바뀌는데, 그러면 내용이 그대로인데도 매번
     * "방금 바뀌었다" 고 알리게 된다. 그런 lastmod 는 크롤러가 곧 무시한다.
     *
     * 사람이 손으로 적는 값이라 잊으면 낡은 채로 남는다. 그쪽이 안전한 방향이다 —
     * 없는 변경을 알리는 것보다 있는 변경을 늦게 알리는 편이 낫다.
     */
    public function testAboutUrlUsesConfiguredLastmod(): void
    {
        $this->seed();

        $block = $this->urlBlock($this->sitemapBody(), $this->baseUrl() . '/about');

        $this->assertStringContainsString('<lastmod>', $block, '/about 에 lastmod 가 없다.');
        $this->assertStringContainsString(
            config('Blog')->aboutUpdatedAt,
            $block,
            'lastmod 가 설정에 적은 날짜와 다르다.'
        );
        $this->assertMatchesRegularExpression(
            '#<lastmod>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}</lastmod>#',
            $block,
            'W3C Datetime 형식이 아니다.'
        );
    }

    /** 발행글 URL 에는 lastmod 가 W3C Datetime 형식으로 붙는다. */
    public function testPostUrlHasIso8601Lastmod(): void
    {
        $this->seed();

        $post  = model(PostModel::class)->where('title', '한글 제목 글')->first();
        $block = $this->urlBlock($this->sitemapBody(), $this->baseUrl() . '/posts/' . rawurlencode($post->slug));

        $this->assertMatchesRegularExpression(
            '#<lastmod>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}</lastmod>#',
            $block,
            'lastmod 가 W3C Datetime 형식이 아니다.'
        );
    }

    /**
     * updated_at 이 비어 있는 글은 lastmod 없이 loc 만 나간다.
     *
     * $useTimestamps 가 채우므로 정상 경로에서는 안 생기지만, 과거 데이터
     * 이관이나 직접 SQL 로 들어온 행이 여기 해당한다. 빈 <lastmod></lastmod>
     * 를 내보내면 규격 위반이라 문서 전체가 거부될 수 있다.
     */
    public function testPostWithoutUpdatedAtHasNoLastmod(): void
    {
        $this->seed();

        $post = model(PostModel::class)->where('title', '한글 제목 글')->first();
        db_connect()->table('posts')->where('id', $post->id)->update(['updated_at' => null]);

        $block = $this->urlBlock($this->sitemapBody(), $this->baseUrl() . '/posts/' . rawurlencode($post->slug));

        $this->assertStringNotContainsString('<lastmod>', $block);
        // 문서 자체는 여전히 유효해야 한다.
        $this->assertNotFalse(simplexml_load_string($this->sitemapBody()));
    }

    /**
     * 응답이 1시간 캐시 가능해야 한다.
     *
     * CI4 Response 생성자는 Cache-Control 을 no-store 로 깔아 두므로, setCache()
     * 를 쓰지 않으면(예: setHeader) no-store 가 앞에 남아 캐싱이 통째로 무효가 된다.
     */
    public function testResponseIsPubliclyCacheable(): void
    {
        $this->seed();

        $cacheControl = $this->call('GET', 'sitemap.xml')->response()->getHeaderLine('Cache-Control');

        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=3600', $cacheControl);
        $this->assertStringNotContainsString('no-store', $cacheControl, 'no-store 가 남으면 캐싱이 무효가 된다.');
    }

    /** 글이 하나도 없어도 유효한 XML 을 낸다(새 블로그의 첫 화면). */
    public function testEmptyBlogStillProducesValidXml(): void
    {
        $parsed = simplexml_load_string($this->sitemapBody());

        $this->assertNotFalse($parsed, '글이 없을 때 XML 이 깨졌다.');
        // 정적 URL 3개는 남는다.
        $this->assertCount(3, $parsed->children(SitemapXml::NAMESPACE_URI));
    }

    /** 라우트의 점이 이스케이프돼 임의 문자로 새지 않는다. */
    public function testDotInRouteIsEscaped(): void
    {
        $this->expectException(PageNotFoundException::class);
        $this->call('GET', 'sitemapaxml');
    }
}
