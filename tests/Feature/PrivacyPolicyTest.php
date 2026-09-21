<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * 개인정보처리방침. (#182)
 *
 * 애드센스는 광고 게재 사이트에 개인정보처리방침을 요구한다. 쿠키를 쓰는 제3자
 * (구글)가 있다는 사실과 이용자가 그것을 끌 수 있다는 안내가 들어 있어야 한다.
 * 이 블로그는 GA4·GTM·애드센스 셋을 모두 붙여 두고도 방침 페이지가 없었다
 * (라이브 실측: /privacy · /terms · /contact 전부 404).
 *
 * 국내 개인정보보호법도 처리방침 공개와 보호책임자 연락처 명시를 요구한다.
 * 그래서 "문서가 존재한다" 가 아니라 **빠지면 안 되는 항목이 들어 있는지**를
 * 본다 — 빈 껍데기 페이지로도 통과하는 테스트는 아무것도 지켜 주지 않는다.
 *
 * @internal
 */
final class PrivacyPolicyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('renderer');
    }

    private function pageText(): string
    {
        return html_entity_decode(
            $this->call('GET', 'privacy')->response()->getBody(),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }

    public function testPrivacyPageReturns200(): void
    {
        $this->call('GET', 'privacy')->assertStatus(200);
    }

    public function testPrivacyPageShowsHeading(): void
    {
        $this->call('GET', 'privacy')->assertSee('개인정보처리방침', 'h1');
    }

    /**
     * 문의 창구가 있어야 한다.
     *
     * 열람·정정·삭제 요구에 응답할 의무가 있으므로, 연락할 방법이 없는 방침은
     * 방침이 아니다. 애드센스 심사도 연락 수단을 본다.
     */
    public function testPrivacyPageGivesAContactAddress(): void
    {
        $this->assertStringContainsString('support@unwanted.me', $this->pageText());
    }

    /**
     * 제3자 쿠키·광고 고지가 있어야 한다.
     *
     * 애드센스가 요구하는 핵심 문단이다. 구글이 쿠키를 써서 광고를 띄운다는 것과,
     * 이용자가 그걸 끌 수 있는 곳을 함께 알려야 한다.
     */
    public function testPrivacyPageDisclosesThirdPartyAdCookies(): void
    {
        $text = $this->pageText();

        $this->assertStringContainsString('쿠키', $text);
        $this->assertStringContainsString('Google AdSense', $text);
        // 광고 설정에서 개인 최적화 광고를 끄는 경로를 실제 링크로 준다.
        $this->assertStringContainsString('https://myadcenter.google.com/', $text);
    }

    /** 무엇을 모으는지 적어야 한다. 이 블로그가 실제로 저장하는 것들이다. */
    public function testPrivacyPageListsCollectedData(): void
    {
        $text = $this->pageText();

        foreach (['이메일', '댓글'] as $item) {
            $this->assertStringContainsString($item, $text, "수집 항목에 '{$item}' 이 없다.");
        }
    }

    /**
     * 모든 화면에서 닿을 수 있어야 한다.
     *
     * 방침이 존재해도 찾을 수 없으면 공개한 것이 아니다. 푸터는 공용 레이아웃과
     * 홈(자체 문서) 양쪽이 같은 partial 을 쓰므로 한쪽만 봐도 되지만, 홈은
     * 레이아웃을 안 쓰는 예외라 홈으로 확인한다.
     */
    public function testFooterLinksToPrivacyPage(): void
    {
        $this->assertStringContainsString(
            site_url('privacy'),
            $this->call('GET', '/')->response()->getBody(),
            '푸터에 개인정보처리방침 링크가 없다.'
        );
    }

    /** sitemap 에 실린다 — 심사·색인 양쪽에서 찾아가야 하는 문서다. */
    public function testSitemapIncludesPrivacyPage(): void
    {
        $xml = $this->call('GET', 'sitemap.xml')->response()->getBody();

        $this->assertStringContainsString('<loc>' . absolute_url('privacy') . '</loc>', $xml);
    }

    /**
     * 색인 대상이다.
     *
     * 광고는 싣지 않지만(AdSensePlacementTest) 검색에서는 보여야 한다.
     * "광고 없음" 을 "존재하지 않음" 으로 번역하지 않는다.
     */
    public function testPrivacyPageIsIndexable(): void
    {
        preg_match(
            '/<meta name="robots" content="([^"]*)"/',
            $this->call('GET', 'privacy')->response()->getBody(),
            $m
        );

        $this->assertSame('index,follow', $m[1] ?? null);
    }
}
