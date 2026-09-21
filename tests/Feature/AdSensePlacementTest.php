<?php

namespace Tests\Feature;

use App\Models\CategoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * 애드센스 광고를 어느 화면에 싣는가. (#182)
 *
 * 구글 게시자 정책은 "콘텐츠가 없는 화면(Screens without publisher content)" 에
 * 광고를 싣는 것을 금지한다. 오류 페이지와 결과가 없는 검색 화면이 여기 해당한다.
 * 애드센스 사이트 심사에서 정책 위반이 잡힌 실제 원인이 이 둘이었다(라이브 실측:
 * /nope-404 와 /posts?q=없는말 이 광고 스크립트를 달고 200/404 를 냈다).
 *
 * 그래서 광고를 **기본값 꺼짐**으로 뒤집고, 콘텐츠가 있는 화면에서만 켠다.
 * 기본값이 켜짐이면 새 화면을 만들 때마다 끄는 것을 잊어야만 위반이 되는데,
 * 그 실수는 계정 정지로 돌아온다. 광고가 빠지는 손해보다 훨씬 크다.
 *
 * ⚠️ 대조군(광고가 **있어야** 하는 화면)을 반드시 함께 둔다. 음성 단언만 있으면
 * 레이아웃에서 광고를 통째로 걷어내도 전부 통과한다 — 수익이 0 이 되는 회귀를
 * 초록으로 덮는 셈이다.
 *
 * @internal
 */
final class AdSensePlacementTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    /**
     * 광고 스크립트를 식별하는 문자열.
     *
     * 게시자 ID(ca-pub-…) 가 아니라 스크립트 URL 을 본다 — ID 는 ads.txt·
     * 계정 문서에도 나오지만, 화면에 광고를 **싣는** 것은 이 스크립트다.
     */
    private const AD_SCRIPT = 'pagead2.googlesyndication.com/pagead/js/adsbygoogle.js';

    protected function setUp(): void
    {
        parent::setUp();

        // 뷰 데이터는 공유 renderer 에 누적된다. 리셋하지 않으면 앞 테스트의
        // $meta 가 남아 `?? false` 기본값을 통과해 거짓 결과가 나온다(#113).
        Services::resetSingle('renderer');
        Services::resetSingle('pager');

        $categories = model(CategoryModel::class);
        $categories->insert(['name' => '회고', 'slug' => 'retro']);

        db_connect()->table('posts')->insert([
            'user_id'     => 1,
            'category_id' => $categories->getInsertID(),
            'title'       => '광고 배치 검증용 글',
            'slug'        => 'ads-fixture',
            'body'        => '본문이 있는 글이므로 광고를 실어도 된다.',
            'status'      => 'published',
            'created_at'  => '2026-05-01 00:00:00',
            'updated_at'  => '2026-05-01 00:00:00',
        ]);
    }

    private function bodyOf(string $path, array $query = []): string
    {
        return $this->call('GET', $path, $query)->response()->getBody();
    }

    /**
     * 404 본문은 call() 로 볼 수 없다 — CI4 는 에러 뷰를 컨트롤러 없이 include 로
     * 렌더하고, call() 에는 PageNotFoundException 이 그대로 올라온다.
     * ErrorPageTest 와 같은 방식으로 예외 핸들러의 경로를 재현한다.
     */
    private function render404(): string
    {
        $message = 'Page Not Found';
        $code    = 404;

        ob_start();
        include APPPATH . 'Views/errors/html/error_404.php';

        return (string) ob_get_clean();
    }

    // ------------------------------------------------ 대조군: 광고가 있어야 한다

    /** 글 상세는 광고를 싣는다. 이 블로그에서 콘텐츠가 가장 확실한 화면이다. */
    public function testPostDetailCarriesAds(): void
    {
        $this->assertStringContainsString(self::AD_SCRIPT, $this->bodyOf('posts/ads-fixture'));
    }

    /**
     * 홈도 광고를 싣는다.
     *
     * 홈은 공유 레이아웃(layouts/default)을 쓰지 않고 자체 문서를 그린다.
     * 레이아웃만 고치면 홈이 조용히 빠지므로 따로 못 박는다.
     */
    public function testHomeCarriesAds(): void
    {
        $this->assertStringContainsString(self::AD_SCRIPT, $this->bodyOf('/'));
    }

    /** 글 목록 1페이지는 글이 실려 있으므로 광고를 싣는다. */
    public function testFirstListPageCarriesAds(): void
    {
        $this->assertStringContainsString(self::AD_SCRIPT, $this->bodyOf('posts'));
    }

    /** 결과가 있는 검색 화면은 콘텐츠가 있다 — 검색이라는 이유만으로 끄지 않는다. */
    public function testSearchResultWithHitsCarriesAds(): void
    {
        $body = $this->bodyOf('posts', ['q' => '광고 배치 검증용']);

        // 먼저 정말로 결과가 나왔는지 고정한다. 결과가 0건이면 이 테스트는
        // "빈 검색에도 광고가 있다" 를 요구하는 정반대 단언이 된다.
        $this->assertStringContainsString('ads-fixture', $body, '검색 결과가 비었다 — 픽스처를 확인할 것.');
        $this->assertStringContainsString(self::AD_SCRIPT, $body);
    }

    /** /about 은 손으로 쓴 소개 글이 있다. */
    public function testAboutCarriesAds(): void
    {
        $this->assertStringContainsString(self::AD_SCRIPT, $this->bodyOf('about'));
    }

    // ------------------------------------------------ 위반: 광고가 없어야 한다

    /**
     * 결과가 0건인 검색 화면에는 광고를 싣지 않는다.
     *
     * 화면에 남는 것은 "'…'에 대한 검색 결과가 없습니다." 한 줄뿐이다.
     * 게다가 ?q= 값은 무한하므로 이런 화면도 무한히 만들 수 있다.
     */
    public function testEmptySearchResultCarriesNoAds(): void
    {
        $body = $this->bodyOf('posts', ['q' => '존재하지않는검색어zzz']);

        // 음성 단언만 두면 페이지가 통째로 비거나 500 이어도 통과한다.
        // 화면이 실제로 그려졌음을 먼저 고정한다.
        $this->assertStringContainsString('검색 결과가 없습니다', html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->assertStringNotContainsString(self::AD_SCRIPT, $body);
    }

    /** 404 오류 페이지에는 광고를 싣지 않는다. 정책이 오류 페이지를 명시적으로 든다. */
    public function testNotFoundPageCarriesNoAds(): void
    {
        $html = $this->render404();

        $this->assertStringContainsString('찾는 글이 없습니다', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->assertStringNotContainsString(self::AD_SCRIPT, $html);
    }

    /** 개인정보처리방침은 고지 문서다 — 광고를 붙이지 않는다. */
    public function testPrivacyPageCarriesNoAds(): void
    {
        $body = $this->bodyOf('privacy');

        $this->assertStringContainsString('개인정보', html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->assertStringNotContainsString(self::AD_SCRIPT, $body);
    }

    /**
     * 로그인 화면에도 광고가 없다.
     *
     * 지금은 auth/layout 을 따로 써서 우연히 없는 상태다. 공용 레이아웃으로
     * 되돌리는 변경이 오면 조용히 위반이 되므로 계약으로 고정한다.
     */
    public function testLoginPageCarriesNoAds(): void
    {
        $body = $this->bodyOf('login');

        $this->assertStringContainsString('form', $body, '로그인 화면이 그려지지 않았다.');
        $this->assertStringNotContainsString(self::AD_SCRIPT, $body);
    }
}
