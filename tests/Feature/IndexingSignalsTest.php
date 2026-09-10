<?php

namespace Tests\Feature;

use App\Models\CategoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * 검색엔진에 보내는 색인 신호 — canonical · robots.
 *
 * Google Search Console 에 미색인 30건이 잡힌 뒤 정리한 계약이다. 색인 여부는
 * 우리가 정하지 못하지만, **모순된 신호를 보내지 않는 것**은 우리 몫이다.
 *
 * 여기서 지키는 것은 두 가지다.
 *
 * 1. canonical 이 자기 자신을 가리킨다(페이지네이션 포함). 예전에는
 *    base_url(uri_string()) 이 쿼리스트링을 통째로 버려서 ?page=2 도, ?page=3 도
 *    전부 /posts 를 정본이라고 선언했다. 그 상태로도 ?page=2 가 색인됐는데,
 *    그건 Google 이 우리 신호를 무시했다는 뜻이지 신호가 옳았다는 뜻이 아니다.
 *
 * 2. 검색 결과(?q=)는 여전히 /posts 로 정규화된다. 검색어는 무한한 URL 을 만들 수
 *    있어서, 자기참조로 열어 주면 색인 대상이 무한히 늘어난다. 그래서 화이트리스트
 *    방식이다 — page 만 남기고 나머지 쿼리는 버린다.
 *
 * 기대값을 base_url(uri_string()) 로 만들지 않는다. 구현과 같은 함수로 같은 값을
 * 두 번 만들면 그 함수가 틀려도 양쪽이 함께 틀려 통과한다.
 */
final class IndexingSignalsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        // 뷰 데이터와 페이지 상태는 공유 서비스에 쌓인다. 리셋하지 않으면 앞
        // 테스트의 meta·currentPage 가 남아 거짓 통과한다.
        Services::resetSingle('renderer');
        Services::resetSingle('pager');

        // 2페이지가 존재하려면 11건이 필요하다(10건/페이지).
        // 전부 한 카테고리에 넣는다 — /categories/{slug} 도 같은 2페이지 구조가 되어야
        // "카테고리 목록의 2페이지" 를 검증할 수 있다.
        $categories = model(CategoryModel::class);
        $categories->insert(['name' => '회고', 'slug' => 'retro']);
        $categoryId = $categories->getInsertID();

        $rows = [];

        for ($i = 1; $i <= 11; $i++) {
            $n       = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $rows[] = [
                'user_id'     => 1,
                'category_id' => $categoryId,
                'title'       => 'SEO-' . $n,
                'slug'        => 'seo-' . $n,
                'body'        => 'SEO-' . $n . ' 본문',
                'status'      => 'published',
                'created_at'  => '2026-05-01 00:' . $n . ':00',
                'updated_at'  => '2026-05-01 00:' . $n . ':00',
            ];
        }

        $db = db_connect();
        $db->table('posts')->insertBatch($rows);

        // 태그 목록도 같은 뷰(posts/index)로 그려진다. 글 하나에만 붙여도 화면이 나온다.
        $db->table('tags')->insert(['name' => '회고록', 'slug' => 'memoir']);
        $tagId = $db->insertID();
        $db->table('post_tags')->insert([
            'post_id' => $db->table('posts')->where('slug', 'seo-01')->get()->getRow()->id,
            'tag_id'  => $tagId,
        ]);
    }

    // ---------------------------------------------------------------- 도우미

    private function canonicalOf(TestResponse $result): ?string
    {
        return preg_match(
            '/<link rel="canonical" href="([^"]*)"/',
            $result->response()->getBody(),
            $m
        ) === 1 ? $m[1] : null;
    }

    private function ogUrlOf(TestResponse $result): ?string
    {
        return preg_match(
            '/<meta property="og:url" content="([^"]*)"/',
            $result->response()->getBody(),
            $m
        ) === 1 ? $m[1] : null;
    }

    /** `<meta name="robots" content="X">` 의 X. 없으면 null. */
    private function robotsOf(TestResponse $result): ?string
    {
        return preg_match(
            '/<meta name="robots" content="([^"]*)"/',
            $result->response()->getBody(),
            $m
        ) === 1 ? $m[1] : null;
    }

    /**
     * `<title>` 안의 글자. og:title 이 아니라 **문서 제목**만 본다.
     *
     * 본문 전체를 뒤지면 안 된다 — 같은 문구가 og:title·h1 에도 있어서, `<title>`
     * 을 통째로 되돌려도 통과하는 위양성이 된다(위양성 유형 9).
     */
    private function titleOf(TestResponse $result): ?string
    {
        $body = html_entity_decode($result->response()->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_match('/<title>(.*?)<\/title>/s', $body, $m) === 1 ? trim($m[1]) : null;
    }

    // ---------------------------------------------------------------- canonical

    /** 2페이지의 정본은 2페이지 자신이다. */
    public function testPaginatedPageCanonicalPointsToItself(): void
    {
        $canonical = $this->canonicalOf($this->call('GET', 'posts', ['page' => '2']));

        $this->assertNotNull($canonical, 'canonical 링크가 없다.');
        $this->assertStringStartsWith('http', $canonical, '절대 URL 이어야 한다.');
        $this->assertStringEndsWith('/posts?page=2', $canonical);
    }

    /**
     * 그래도 홈의 정본에는 슬래시가 남는다.
     *
     * 끝 슬래시를 일괄로 떼면 홈이 `https://example.com` 이 되어, 이미 색인된 형태
     * (`https://example.com/`)·sitemap 의 `<loc>` 와 어긋난다. 루트만은 예외다.
     */
    public function testHomeCanonicalKeepsTheRootSlash(): void
    {
        $canonical = $this->canonicalOf($this->call('GET', '/'));

        $this->assertNotNull($canonical, 'canonical 링크가 없다.');
        // 접미사만 보면 호스트가 뭐든 슬래시로 끝나기만 하면 통과한다. 값 전체를 본다.
        // (base_url() 자체의 정확성은 이 테스트의 몫이 아니라 링크·sitemap 쪽에서 다룬다.)
        $this->assertSame(base_url(), $canonical);
    }

    /**
     * 끝 슬래시가 붙어 들어와도 정본은 슬래시 없는 주소다.
     *
     * `/posts/` 와 `/posts` 는 같은 내용인데, 라이브에서 전자가 **자기 자신을** 정본이라
     * 선언하고 있었다. 그러면 같은 글이 두 정본을 갖고 색인 경쟁을 하며, 크롤 예산도
     * 두 배로 먹는다. GSC 의 "적절한 표준 태그가 포함된 대체 페이지" 가 이런 중복에서 쌓인다.
     */
    public function testTrailingSlashCanonicalDropsTheSlash(): void
    {
        $canonical = $this->canonicalOf($this->call('GET', 'posts/'));

        $this->assertNotNull($canonical, 'canonical 링크가 없다.');
        // 접미사 비교는 잘못된 호스트·경로를 놓친다. 값 전체가 정본과 같아야 한다.
        $this->assertSame(base_url('posts'), $canonical);
    }

    /**
     * 첫 페이지는 ?page=1 을 붙이지 않는다.
     *
     * /posts 와 /posts?page=1 은 같은 내용이다. 둘 다 정본이라고 선언하면
     * 우리 손으로 중복을 하나 만드는 셈이다.
     */
    public function testFirstPageCanonicalHasNoPageQuery(): void
    {
        $canonical = $this->canonicalOf($this->call('GET', 'posts', ['page' => '1']));

        // canonicalOf() 는 ?string 이다. null 을 그대로 넘기면 단언 실패가 아니라
        // TypeError 가 나서 진짜 원인(canonical 이 사라졌다)이 가려진다.
        $this->assertNotNull($canonical, 'canonical 링크가 없다.');
        $this->assertStringEndsWith('/posts', $canonical);
    }

    /**
     * 검색 결과는 계속 /posts 로 정규화된다.
     *
     * 검색어는 무한히 만들 수 있다. 자기참조로 열어 주면 색인 후보가 무한해진다.
     */
    public function testSearchResultCanonicalDropsQuery(): void
    {
        $canonical = $this->canonicalOf($this->call('GET', 'posts', ['q' => '검색어']));

        $this->assertNotNull($canonical, 'canonical 링크가 없다.');
        $this->assertStringEndsWith('/posts', $canonical);
        $this->assertStringNotContainsString('q=', $canonical);
    }

    /** page 만 남기고 나머지는 버린다 — 화이트리스트라는 사실 자체를 못 박는다. */
    public function testOnlyPageSurvivesAmongQueryParams(): void
    {
        $canonical = $this->canonicalOf(
            $this->call('GET', 'posts', ['page' => '2', 'q' => 'SEO', 'utm_source' => 'x'])
        );

        $this->assertNotNull($canonical, 'canonical 링크가 없다.');
        $this->assertStringEndsWith('/posts?page=2', $canonical);
        $this->assertStringNotContainsString('utm_source', $canonical);
    }

    // ---------------------------------------------------------------- robots

    /**
     * 로그인 화면은 색인 대상이 아니다.
     *
     * 검색 결과에 로그인 폼이 뜰 이유가 없고, 실제로 GSC 미색인 목록에 올라와
     * 있었다. 색인되지 않는 것 자체는 옳은데, 그게 **우리가 그렇게 정해서**가
     * 아니라 Google 이 알아서 거른 결과였다. 의도를 명시한다.
     */
    public function testLoginPageIsNoindex(): void
    {
        $html = $this->call('GET', 'login')->response()->getBody();

        $this->assertMatchesRegularExpression(
            '/<meta name="robots" content="[^"]*noindex/',
            $html,
            '로그인 화면에 noindex 가 없다.'
        );
    }

    /**
     * 공개 페이지는 noindex 가 아니다.
     *
     * 위 테스트만 있으면 레이아웃 전체에 noindex 를 붙여도 통과한다. 그건 사이트를
     * 통째로 검색에서 지우는 사고이고, 되돌려도 회복에 몇 주가 걸린다.
     */
    public function testPublicPagesAreNotNoindex(): void
    {
        foreach (['/', 'posts', 'about'] as $path) {
            $html = $this->call('GET', $path)->response()->getBody();

            $this->assertStringNotContainsString('noindex', $html, "{$path} 가 noindex 로 나간다.");
        }
    }

    /** og:url 은 canonical 과 같아야 한다. 정본이 둘로 갈라지면 안 된다. */
    public function testOgUrlFollowsCanonicalOnPaginatedPage(): void
    {
        $result = $this->call('GET', 'posts', ['page' => '2']);

        $this->assertSame($this->canonicalOf($result), $this->ogUrlOf($result));
    }

    // ------------------------------------------------- robots: 중복 목록 페이지
    //
    // GSC 가 "발견됨 - 색인 생성 안 됨" 으로 잡은 11건의 정체는 서로 똑같은 목록
    // 페이지들이었다. /posts · /posts?page=2,3 · /categories/codeigniter4 ·
    // 그 페이지네이션 · /tags/{slug} 가 전부 같은 뷰에 같은 <title> 을 달고
    // 저마다 자기를 정본이라 선언하고 있었다. 크롤 예산이 여기로 샜다.
    //
    // 그래서 **목록의 1페이지 하나만** 색인 대상으로 남기고 나머지는 접는다.
    // 카테고리·태그는 1페이지까지 접는다 — 지금은 카테고리가 하나뿐이라
    // /posts 와 글 묶음이 100% 같고, 태그도 /posts 의 부분집합이다.

    /** 목록의 1페이지는 색인 대상이다. 여기까지 접으면 글 목록이 검색에서 통째로 사라진다. */
    public function testFirstListPageIsIndexable(): void
    {
        $this->assertSame('index,follow', $this->robotsOf($this->call('GET', 'posts')));
    }

    /**
     * 2페이지부터는 noindex 다 — 단 follow 는 남긴다.
     *
     * nofollow 로 막으면 그 목록에만 걸려 있는 개별 글로 가는 길이 끊긴다.
     * 접으려는 것은 목록이지 글이 아니다. 값을 통째로 비교하는 이유가 이것이다 —
     * 'noindex' 가 들어 있는지만 보면 noindex,nofollow 로 바뀌어도 통과한다.
     */
    public function testPaginatedListIsNoindexButStillFollowed(): void
    {
        $this->assertSame('noindex,follow', $this->robotsOf($this->call('GET', 'posts', ['page' => '2'])));
    }

    /**
     * 카테고리 목록은 1페이지도 noindex 다.
     *
     * 카테고리가 하나뿐인 동안은 /categories/{slug} 가 /posts 와 완전히 같은 글
     * 묶음을 보여 준다. 카테고리가 늘어 묶음이 갈라지면 1페이지만 되돌린다.
     */
    public function testCategoryListIsNoindexEvenOnFirstPage(): void
    {
        $this->assertSame('noindex,follow', $this->robotsOf($this->call('GET', 'categories/retro')));
    }

    /** 카테고리의 2페이지도 마찬가지다. */
    public function testPaginatedCategoryListIsNoindex(): void
    {
        $this->assertSame(
            'noindex,follow',
            $this->robotsOf($this->call('GET', 'categories/retro', ['page' => '2']))
        );
    }

    /**
     * 태그 목록도 noindex 다.
     *
     * sitemap 에는 없지만 **글 상세마다 태그 링크가 걸려 있어** 크롤러가 전부
     * 따라간다. 카테고리 하나보다 태그 쪽 수가 훨씬 많아 새는 예산도 더 크다.
     */
    public function testTagListIsNoindex(): void
    {
        $this->assertSame('noindex,follow', $this->robotsOf($this->call('GET', 'tags/memoir')));
    }

    /**
     * 개별 글은 색인 대상이다.
     *
     * 이번 작업의 목적은 목록을 접어 **글로 크롤 예산을 몰아주는 것**이다.
     * 글까지 접히면 목적과 정반대가 된다.
     */
    public function testPostDetailIsIndexable(): void
    {
        $this->assertSame('index,follow', $this->robotsOf($this->call('GET', 'posts/seo-01')));
    }

    /**
     * 로그인 화면의 robots 는 정확히 한 번만 나간다.
     *
     * 로그인은 공용 레이아웃이 아니라 auth/layout 을 쓴다. 공용 쪽에 robots 를
     * 붙이면서 둘 다 나가면 크롤러에게 신호를 두 번 보내는 셈이고, 값이 갈리면
     * 어느 쪽이 이길지는 우리가 정하지 못한다.
     */
    public function testLoginPageEmitsRobotsExactlyOnce(): void
    {
        $html = $this->call('GET', 'login')->response()->getBody();

        $this->assertSame(1, preg_match_all('/<meta name="robots"/', $html));
    }

    // ------------------------------------------------- title: 목록 페이지 구분
    //
    // 여섯 개 목록 URL 의 <title> 이 전부 '글 목록 · nambak80 Blog' 이었다(라이브
    // 실측). 제목이 같으면 사람도 검색엔진도 구별하지 못한다.
    //
    // 값은 og:title 과 같은 $meta['title'] 하나에서 나온다. 예전에는 <title> 이
    // 뷰에 박힌 상수라, 카테고리 페이지의 og:title 이 '회고 글' 인데 <title> 은
    // '글 목록' 인 상태로 이미 갈라져 있었다.

    /** 1페이지 제목에는 페이지 번호가 붙지 않는다. `글 목록 ·` 로 시작하면 번호가 없다는 뜻이다. */
    public function testFirstListPageTitleHasNoPageNumber(): void
    {
        $this->assertStringStartsWith('글 목록 ·', (string) $this->titleOf($this->call('GET', 'posts')));
    }

    /** 2페이지 제목에는 몇 페이지인지가 들어간다. */
    public function testPaginatedListTitleCarriesThePageNumber(): void
    {
        $this->assertStringStartsWith(
            '글 목록 (2페이지)',
            (string) $this->titleOf($this->call('GET', 'posts', ['page' => '2']))
        );
    }

    /** 카테고리 목록 제목에는 카테고리 이름이 들어간다. */
    public function testCategoryListTitleNamesTheCategory(): void
    {
        $this->assertStringStartsWith('회고 글 ·', (string) $this->titleOf($this->call('GET', 'categories/retro')));
    }

    /** 카테고리 2페이지 제목에는 이름과 페이지 번호가 함께 들어간다. */
    public function testPaginatedCategoryTitleNamesBoth(): void
    {
        $this->assertStringStartsWith(
            '회고 글 (2페이지)',
            (string) $this->titleOf($this->call('GET', 'categories/retro', ['page' => '2']))
        );
    }

    /** 태그 목록 제목에는 태그 이름이 들어간다. */
    public function testTagListTitleNamesTheTag(): void
    {
        $this->assertStringStartsWith('회고록 태그 글 ·', (string) $this->titleOf($this->call('GET', 'tags/memoir')));
    }

    /** <title> 과 og:title 은 같은 값에서 나온다 — 갈라지면 어느 쪽이 진짜인지 알 수 없다. */
    public function testDocumentTitleMatchesOgTitleOnCategoryPage(): void
    {
        $result = $this->call('GET', 'categories/retro');

        $html = html_entity_decode($result->response()->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match('/<meta property="og:title" content="([^"]*)"/', $html, $m);

        $this->assertNotEmpty($m, 'og:title 이 없다.');
        $this->assertStringStartsWith($m[1] . ' ·', (string) $this->titleOf($result));
    }
}
