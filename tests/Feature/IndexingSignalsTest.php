<?php

namespace Tests\Feature;

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
        $rows = [];

        for ($i = 1; $i <= 11; $i++) {
            $n       = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $rows[] = [
                'user_id'    => 1,
                'title'      => 'SEO-' . $n,
                'slug'       => 'seo-' . $n,
                'body'       => 'SEO-' . $n . ' 본문',
                'status'     => 'published',
                'created_at' => '2026-05-01 00:' . $n . ':00',
                'updated_at' => '2026-05-01 00:' . $n . ':00',
            ];
        }

        db_connect()->table('posts')->insertBatch($rows);
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
     * 첫 페이지는 ?page=1 을 붙이지 않는다.
     *
     * /posts 와 /posts?page=1 은 같은 내용이다. 둘 다 정본이라고 선언하면
     * 우리 손으로 중복을 하나 만드는 셈이다.
     */
    public function testFirstPageCanonicalHasNoPageQuery(): void
    {
        $canonical = $this->canonicalOf($this->call('GET', 'posts', ['page' => '1']));

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

        $this->assertStringEndsWith('/posts', $canonical);
        $this->assertStringNotContainsString('q=', $canonical);
    }

    /** page 만 남기고 나머지는 버린다 — 화이트리스트라는 사실 자체를 못 박는다. */
    public function testOnlyPageSurvivesAmongQueryParams(): void
    {
        $canonical = $this->canonicalOf(
            $this->call('GET', 'posts', ['page' => '2', 'q' => 'SEO', 'utm_source' => 'x'])
        );

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
}
