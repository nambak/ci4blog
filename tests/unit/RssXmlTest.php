<?php

use App\Libraries\RssXml;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * RSS 2.0 직렬화의 순수 로직. DB·라우팅과 무관하다. (#113)
 *
 * @internal
 */
final class RssXmlTest extends CIUnitTestCase
{
    /** @return array{title: string, link: string, description: string, feedUrl: string} */
    private function channel(): array
    {
        return [
            'title'       => '테스트 블로그',
            'link'        => 'https://example.com/',
            'description' => '설명',
            'feedUrl'     => 'https://example.com/feed',
        ];
    }

    /**
     * 항목이 없어도 파싱 가능한 XML 이어야 한다.
     *
     * 발행글이 0건인 새 블로그에서 실제로 밟는 경로다. 깨진 XML 을 내면
     * 리더가 피드 자체를 버린다.
     */
    public function testEmptyItemsProduceValidRss(): void
    {
        $xml = (new RssXml())->render($this->channel(), []);

        $parsed = simplexml_load_string($xml);

        $this->assertNotFalse($parsed, '빈 목록도 파싱 가능한 XML 이어야 한다.');
        $this->assertSame('rss', $parsed->getName());
        $this->assertSame('2.0', (string) $parsed['version']);
        $this->assertCount(0, $parsed->channel->item);
    }

    /** atom 네임스페이스가 선언된다 — 없으면 atom:link 가 규격 위반이 된다. */
    public function testRootDeclaresAtomNamespace(): void
    {
        $xml = (new RssXml())->render($this->channel(), []);

        $parsed = simplexml_load_string($xml);
        $this->assertNotFalse($parsed);

        $this->assertContains(RssXml::ATOM_NAMESPACE_URI, $parsed->getDocNamespaces());
    }

    /** channel 메타가 그대로 실린다. */
    public function testRendersChannelMetadata(): void
    {
        $xml = (new RssXml())->render($this->channel(), []);

        $parsed = simplexml_load_string($xml);
        $this->assertNotFalse($parsed);

        $this->assertSame('테스트 블로그', (string) $parsed->channel->title);
        $this->assertSame('https://example.com/', (string) $parsed->channel->link);
        $this->assertSame('설명', (string) $parsed->channel->description);
        $this->assertSame('ko-kr', (string) $parsed->channel->language);
    }

    /** atom:link rel="self" 가 피드 자신의 주소를 가리킨다(피드 검증기 권장 항목). */
    public function testRendersSelfLink(): void
    {
        $xml = (new RssXml())->render($this->channel(), []);

        $parsed = simplexml_load_string($xml);
        $this->assertNotFalse($parsed);

        $atom = $parsed->channel->children(RssXml::ATOM_NAMESPACE_URI);

        $this->assertSame('https://example.com/feed', (string) $atom->link->attributes()['href']);
        $this->assertSame('self', (string) $atom->link->attributes()['rel']);
    }

    /**
     * lastBuildDate 값이 없으면 태그 자체가 없어야 한다.
     *
     * 빈 <lastBuildDate></lastBuildDate> 는 규격 위반이다. 값이 있을 때와
     * 없을 때를 함께 봐야 "항상 넣는" 구현과 "항상 빼는" 구현이 모두 걸린다.
     */
    public function testOmitsLastBuildDateWhenMissing(): void
    {
        $rss = new RssXml();

        $withDate = $rss->render($this->channel() + ['lastBuildDate' => 'Fri, 31 Jul 2026 10:30:00 +0900'], []);
        $without  = $rss->render($this->channel(), []);

        $this->assertStringContainsString('<lastBuildDate>Fri, 31 Jul 2026 10:30:00 +0900</lastBuildDate>', $withDate);
        $this->assertStringNotContainsString('lastBuildDate', $without);
    }

    /** item 필드가 전부 실린다. */
    public function testRendersItemFields(): void
    {
        $xml = (new RssXml())->render($this->channel(), [[
            'title'       => '첫 글',
            'link'        => 'https://example.com/posts/first',
            'guid'        => 'https://example.com/posts/id/1',
            'pubDate'     => 'Fri, 31 Jul 2026 10:30:00 +0900',
            'description' => '요약',
        ]]);

        $parsed = simplexml_load_string($xml);
        $this->assertNotFalse($parsed);

        $item = $parsed->channel->item[0];

        $this->assertSame('첫 글', (string) $item->title);
        $this->assertSame('https://example.com/posts/first', (string) $item->link);
        $this->assertSame('https://example.com/posts/id/1', (string) $item->guid);
        $this->assertSame('Fri, 31 Jul 2026 10:30:00 +0900', (string) $item->pubDate);
        $this->assertSame('요약', (string) $item->description);
    }

    /**
     * guid 에 isPermaLink="false" 가 붙는다.
     *
     * 이 속성이 없으면 기본값이 true 라, 리더가 guid 를 열 수 있는 주소로 여긴다.
     * 우리 guid 는 라우트가 없는 식별자라 404 로 이어진다.
     */
    public function testGuidIsNotAPermaLink(): void
    {
        $xml = (new RssXml())->render($this->channel(), [[
            'title'       => '첫 글',
            'link'        => 'https://example.com/posts/first',
            'guid'        => 'https://example.com/posts/id/1',
            'pubDate'     => 'Fri, 31 Jul 2026 10:30:00 +0900',
            'description' => '요약',
        ]]);

        $parsed = simplexml_load_string($xml);
        $this->assertNotFalse($parsed);

        $this->assertSame('false', (string) $parsed->channel->item[0]->guid['isPermaLink']);
    }

    /**
     * XML 특수문자가 이스케이프된다.
     *
     * 이스케이프가 빠지면 '&' 하나로 문서 전체가 파싱 불가가 된다.
     * 파싱 성공과 원문 복원을 함께 봐야 "무조건 지워 버리는" 구현도 걸린다.
     */
    public function testEscapesXmlSpecialCharacters(): void
    {
        $xml = (new RssXml())->render($this->channel(), [[
            'title'       => 'A & B <태그>',
            'link'        => 'https://example.com/posts/a?x=1&y=2',
            'guid'        => 'https://example.com/posts/id/1',
            'pubDate'     => 'Fri, 31 Jul 2026 10:30:00 +0900',
            'description' => '따옴표 " 와 앰퍼샌드 &',
        ]]);

        $parsed = simplexml_load_string($xml);

        $this->assertNotFalse($parsed, '특수문자가 있어도 파싱되어야 한다.');
        $this->assertSame('A & B <태그>', (string) $parsed->channel->item[0]->title);
        $this->assertSame('https://example.com/posts/a?x=1&y=2', (string) $parsed->channel->item[0]->link);
        $this->assertSame('따옴표 " 와 앰퍼샌드 &', (string) $parsed->channel->item[0]->description);
    }

    /** XML 선언이 첫 바이트부터 시작한다 — 앞에 공백 한 바이트만 새도 파싱이 깨진다. */
    public function testStartsWithXmlDeclaration(): void
    {
        $xml = (new RssXml())->render($this->channel(), []);

        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
    }
}
