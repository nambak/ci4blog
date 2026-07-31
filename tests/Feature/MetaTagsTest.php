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
}
