<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * 구글 태그 관리자(GTM) 연동. (#151)
 *
 * 모든 공개 페이지의 <head> 최상단에 GTM 스크립트를, <body> 여는 태그 바로 뒤에
 * noscript iframe 을 심어야 컨테이너가 정상 동작한다. 위치가 어긋나면 GTM 콘솔이
 * "설치 확인 안 됨" 으로 표시하므로 위치까지 함께 검증한다.
 *
 * 홈(home/index.php)은 공유 레이아웃(layouts/default)을 쓰지 않고 자체 문서를
 * 그리므로, 두 템플릿 모두 확인한다.
 *
 * @internal
 */
final class GoogleTagManagerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    private const GTM_ID = 'GTM-NVTRSRQD';

    public static function pageProvider(): iterable
    {
        yield '홈(자체 문서)' => ['/'];
        yield 'about(공유 레이아웃)' => ['about'];
    }

    /** @dataProvider pageProvider */
    public function testHeadStartsWithGtmScript(string $path): void
    {
        $html = $this->call('GET', $path)->response()->getBody();

        $this->assertMatchesRegularExpression(
            '/<head>\s*<script>\(function\(w,d,s,l,i\).*?GTM-NVTRSRQD.*?<\/script>/s',
            $html,
            'GTM 스크립트가 head 맨 위에 없다.'
        );
        $this->assertStringContainsString(
            "https://www.googletagmanager.com/gtm.js?id='+i",
            $html
        );
    }

    /** @dataProvider pageProvider */
    public function testBodyStartsWithGtmNoscript(string $path): void
    {
        $html = $this->call('GET', $path)->response()->getBody();

        $this->assertMatchesRegularExpression(
            '/<body[^>]*>\s*<noscript><iframe src="https:\/\/www\.googletagmanager\.com\/ns\.html\?id=' . self::GTM_ID . '"/',
            $html,
            'GTM noscript iframe 이 body 여는 태그 바로 뒤에 없다.'
        );
    }
}
