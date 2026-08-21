<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * 구글 애널리틱스(GA4) 연동. (#167)
 *
 * 이슈에 첨부된 구글 태그(gtag.js) 스니펫을 <head> 요소 바로 다음에 한 번만
 * 심어야 애널리틱스 속성이 설치를 인식한다.
 *
 * 홈(home/index.php)은 공유 레이아웃(layouts/default)을 쓰지 않고 자체 문서를
 * 그리므로, 두 템플릿 모두 확인한다.
 *
 * @internal
 */
final class GoogleAnalyticsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    private const GA_ID = 'G-W1YQS7P9RH';

    public static function pageProvider(): iterable
    {
        yield '홈(자체 문서)' => ['/'];
        yield 'about(공유 레이아웃)' => ['about'];
    }

    /** @dataProvider pageProvider */
    public function testHeadStartsWithGoogleTagSnippet(string $path): void
    {
        $html = $this->call('GET', $path)->response()->getBody();

        $this->assertMatchesRegularExpression(
            '/<head>\s*<script async src="https:\/\/www\.googletagmanager\.com\/gtag\/js\?id=' . self::GA_ID . '"><\/script>/s',
            $html,
            '구글 태그(gtag.js) 스크립트가 head 요소 바로 다음에 없다.'
        );
    }

    /** @dataProvider pageProvider */
    public function testGtagConfigIsCalledOnceWithGaId(string $path): void
    {
        $html = $this->call('GET', $path)->response()->getBody();

        $this->assertSame(
            1,
            substr_count($html, "gtag('config', '" . self::GA_ID . "')"),
            '구글 태그 설정 호출이 정확히 한 번만 있어야 한다.'
        );
    }
}
