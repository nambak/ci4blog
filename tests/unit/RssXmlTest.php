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

    /**
     * pubDate 값이 없으면 태그 자체가 없어야 한다.
     *
     * posts.created_at 은 마이그레이션상 nullable 이라 실제로 null 이 들어올 수
     * 있다(#113 최종 리뷰 I1). 빈 <pubDate></pubDate> 는 규격 위반이므로, 값이
     * 있을 때와 없을 때를 함께 봐야 "항상 넣는" 구현을 배제할 수 있다.
     */
    public function testOmitsPubDateWhenMissing(): void
    {
        $rss = new RssXml();

        $item = static fn ($pubDate) => [
            'title'       => '첫 글',
            'link'        => 'https://example.com/posts/first',
            'guid'        => 'https://example.com/posts/id/1',
            'pubDate'     => $pubDate,
            'description' => '요약',
        ];

        $withDate = $rss->render($this->channel(), [$item('Fri, 31 Jul 2026 10:30:00 +0900')]);
        $without  = $rss->render($this->channel(), [$item(null)]);

        $this->assertStringContainsString('<pubDate>Fri, 31 Jul 2026 10:30:00 +0900</pubDate>', $withDate);
        $this->assertStringNotContainsString('<pubDate>', $without);
        $this->assertNotFalse(simplexml_load_string($without), 'pubDate 가 없어도 유효한 XML 이어야 한다.');
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

    /**
     * XML 1.0 이 금지하는 제어문자가 자유 텍스트(제목)에 섞여도 파싱이 깨지지
     * 않아야 한다. (#113 최종 리뷰 M1)
     *
     * htmlspecialchars 는 이런 제어문자를 이스케이프하지 않고 그대로 통과시킨다 —
     * 하나라도 남으면 문서 전체가 파싱 불가가 된다. 제어문자만 사라지고 나머지
     * 텍스트는 보존되는지 함께 봐야 "통째로 지워 버리는" 구현도 걸린다.
     */
    public function testStripsXmlIllegalControlCharactersFromFreeText(): void
    {
        // \x0B(수직탭)·\x0C(폼피드) 는 XML 1.0 이 금지하는 제어문자다.
        $title = "제목\x0B중간\x0C끝";

        $xml = (new RssXml())->render($this->channel(), [[
            'title'       => $title,
            'link'        => 'https://example.com/posts/first',
            'guid'        => 'https://example.com/posts/id/1',
            'pubDate'     => 'Fri, 31 Jul 2026 10:30:00 +0900',
            'description' => '요약',
        ]]);

        $parsed = simplexml_load_string($xml);

        $this->assertNotFalse($parsed, '제어문자가 섞이면 파싱이 깨진다.');
        $this->assertSame(
            '제목중간끝',
            (string) $parsed->channel->item[0]->title,
            '제어문자만 사라지고 나머지 텍스트는 보존돼야 한다.'
        );
    }

    /**
     * 탭·개행·캐리지리턴은 XML 1.0 이 허용하는 제어문자라 지우면 안 된다.
     *
     * 위 테스트와 짝을 이룬다 — "제어문자를 전부 지우는" 과잉 구현이 아니라
     * "금지된 것만" 지우는 구현인지를 이 테스트가 가른다.
     */
    public function testKeepsAllowedWhitespaceControlCharacters(): void
    {
        $description = "첫 줄\n둘째 줄\t끝";

        $xml = (new RssXml())->render($this->channel(), [[
            'title'       => '제목',
            'link'        => 'https://example.com/posts/first',
            'guid'        => 'https://example.com/posts/id/1',
            'pubDate'     => 'Fri, 31 Jul 2026 10:30:00 +0900',
            'description' => $description,
        ]]);

        $parsed = simplexml_load_string($xml);

        $this->assertNotFalse($parsed);
        $this->assertSame($description, (string) $parsed->channel->item[0]->description);
    }

    /**
     * 유효하지 않은 UTF-8 이 섞여도 엘리먼트가 통째로 비지 않는다. (#113 최종 리뷰 M1)
     *
     * ENT_SUBSTITUTE 없이 htmlspecialchars 를 쓰면 유효하지 않은 UTF-8 입력에
     * 빈 문자열을 돌려준다 — 값이 있는데 조용히 사라지는 것은 제어문자가
     * 남는 것보다 알아채기 어렵다(파싱은 되지만 내용이 없다).
     */
    public function testInvalidUtf8DoesNotBlankOutTheElement(): void
    {
        // "제목" 뒤에 잘못된 연속 바이트(0x80)를 붙여 유효하지 않은 UTF-8 을 만든다.
        $title = "제목\x80끝";

        $xml = (new RssXml())->render($this->channel(), [[
            'title'       => $title,
            'link'        => 'https://example.com/posts/first',
            'guid'        => 'https://example.com/posts/id/1',
            'pubDate'     => 'Fri, 31 Jul 2026 10:30:00 +0900',
            'description' => '요약',
        ]]);

        $parsed = simplexml_load_string($xml);

        $this->assertNotFalse($parsed, '유효하지 않은 UTF-8 이 섞이면 파싱이 깨진다.');
        $this->assertNotSame(
            '',
            (string) $parsed->channel->item[0]->title,
            'ENT_SUBSTITUTE 가 없으면 엘리먼트가 통째로 비어 버린다.'
        );
    }
}
