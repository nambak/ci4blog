<?php

use App\Libraries\SitemapXml;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * sitemap XML 직렬화의 순수 로직. DB·라우팅과 무관하다.
 *
 * @internal
 */
final class SitemapXmlTest extends CIUnitTestCase
{
    /**
     * 항목이 없어도 파싱 가능한 XML 이어야 한다.
     *
     * 발행글이 0건인 새 블로그에서 실제로 밟는 경로다. 여기서 깨진 XML 을 내면
     * 크롤러가 sitemap 자체를 버린다.
     */
    public function testEmptyEntriesProduceValidEmptyUrlset(): void
    {
        $xml = (new SitemapXml())->render([]);

        $parsed = simplexml_load_string($xml);

        $this->assertNotFalse($parsed, '빈 목록도 파싱 가능한 XML 이어야 한다.');
        $this->assertSame('urlset', $parsed->getName());

        // 기본 네임스페이스가 선언돼 있으면 SimpleXML 은 $parsed->url 로 자식에
        // 접근하지 못한다. 반드시 children(네임스페이스) 를 거쳐야 한다.
        $this->assertCount(0, $parsed->children(SitemapXml::NAMESPACE_URI));
    }

    /** 네임스페이스 선언이 규격대로 붙는다 — 없으면 크롤러가 문서를 인식하지 못한다. */
    public function testRootDeclaresSitemapNamespace(): void
    {
        $xml = (new SitemapXml())->render([['loc' => 'https://example.com/']]);

        $parsed = simplexml_load_string($xml);
        $this->assertNotFalse($parsed);

        $namespaces = $parsed->getDocNamespaces();
        $this->assertContains(SitemapXml::NAMESPACE_URI, $namespaces);
    }

    /** loc 이 그대로 나온다. */
    public function testRendersLocForEachEntry(): void
    {
        $xml = (new SitemapXml())->render([
            ['loc' => 'https://example.com/a'],
            ['loc' => 'https://example.com/b'],
        ]);

        $this->assertStringContainsString('<loc>https://example.com/a</loc>', $xml);
        $this->assertStringContainsString('<loc>https://example.com/b</loc>', $xml);
    }

    /**
     * XML 특수문자는 이스케이프한다.
     *
     * 쿼리스트링이 붙은 URL 을 넣으면 & 가 그대로 나가 문서 전체가 깨진다.
     */
    public function testSpecialCharactersInLocAreEscaped(): void
    {
        $xml = (new SitemapXml())->render([['loc' => 'https://example.com/?a=1&b=2']]);

        $this->assertStringContainsString('<loc>https://example.com/?a=1&amp;b=2</loc>', $xml);
        // 날 & 가 남아 있으면 아래 파싱이 실패한다 — 그래서 파싱까지 확인한다.
        $this->assertNotFalse(simplexml_load_string($xml), '이스케이프가 빠지면 XML 이 깨진다.');
    }

    /** lastmod 가 있으면 그대로 출력한다. */
    public function testRendersLastmodWhenPresent(): void
    {
        $xml = (new SitemapXml())->render([
            ['loc' => 'https://example.com/posts/x', 'lastmod' => '2026-07-30T10:28:34+00:00'],
        ]);

        $this->assertStringContainsString('<lastmod>2026-07-30T10:28:34+00:00</lastmod>', $xml);
    }

    /**
     * lastmod 가 없으면 태그 자체를 뺀다.
     *
     * 빈 <lastmod></lastmod> 는 규격 위반이고, 근거 없는 시각을 채우면
     * 크롤러가 매번 "바뀌었다" 로 읽어 신뢰를 잃는다. /about 이 이 경로다.
     */
    public function testOmitsLastmodTagWhenMissingOrNull(): void
    {
        $xml = (new SitemapXml())->render([
            ['loc' => 'https://example.com/about'],
            ['loc' => 'https://example.com/contact', 'lastmod' => null],
            ['loc' => 'https://example.com/empty', 'lastmod' => ''],
        ]);

        $this->assertStringContainsString('<loc>https://example.com/about</loc>', $xml);
        $this->assertStringNotContainsString('<lastmod>', $xml, 'lastmod 가 없으면 태그가 나오면 안 된다.');
    }
}
