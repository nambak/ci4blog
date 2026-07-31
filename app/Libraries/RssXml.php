<?php

namespace App\Libraries;

/**
 * 피드 항목 배열을 RSS 2.0 XML 로 직렬화한다. (#113)
 *
 * DB·프레임워크에 의존하지 않는 순수 변환이다. 뷰 파일로 만들지 않은 이유는
 * SitemapXml 과 같다 — <?xml 선언 앞에 공백 한 바이트만 새어도 파싱이 통째로
 * 깨지는데, 이 저장소의 뷰는 전부 레이아웃 상속이라 그 위험이 특히 크다.
 */
class RssXml
{
    /** atom:link rel="self" 를 쓰기 위한 네임스페이스. 피드 검증기가 권장하는 항목이다. */
    public const ATOM_NAMESPACE_URI = 'http://www.w3.org/2005/Atom';

    /**
     * @param array{title: string, link: string, description: string, feedUrl: string, lastBuildDate?: string|null} $channel
     * @param list<array{title: string, link: string, guid: string, pubDate: string|null, description: string}>     $items
     */
    public function render(array $channel, array $items): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:atom="' . self::ATOM_NAMESPACE_URI . '">' . "\n"
            . '  <channel>' . "\n"
            . '    <title>' . $this->escape($channel['title']) . '</title>' . "\n"
            . '    <link>' . $this->escape($channel['link']) . '</link>' . "\n"
            . '    <description>' . $this->escape($channel['description']) . '</description>' . "\n"
            . '    <language>ko-kr</language>' . "\n"
            . '    <atom:link href="' . $this->escape($channel['feedUrl']) . '" rel="self" type="application/rss+xml"/>' . "\n";

        // 값이 없으면 태그 자체를 뺀다. 빈 <lastBuildDate></lastBuildDate> 는 규격 위반이다.
        if (isset($channel['lastBuildDate']) && $channel['lastBuildDate'] !== '') {
            $xml .= '    <lastBuildDate>' . $this->escape($channel['lastBuildDate']) . '</lastBuildDate>' . "\n";
        }

        foreach ($items as $item) {
            // guid 는 라우트가 없는 식별자 문자열이다. isPermaLink="false" 가 "이건
            // 주소가 아니다" 를 규격상 선언하므로 유효하다 — 속성을 빼면 기본값이
            // true 라 리더가 열려다 404 를 만난다.
            $xml .= '    <item>' . "\n"
                . '      <title>' . $this->escape($item['title']) . '</title>' . "\n"
                . '      <link>' . $this->escape($item['link']) . '</link>' . "\n"
                . '      <guid isPermaLink="false">' . $this->escape($item['guid']) . '</guid>' . "\n";

            // pubDate 는 RSS 2.0 규격상 선택 항목이다 — 값이 없으면 태그 자체를
            // 뺀다(lastBuildDate 와 같은 규칙). 빈 <pubDate></pubDate> 는 규격 위반이다.
            if (isset($item['pubDate']) && $item['pubDate'] !== '') {
                $xml .= '      <pubDate>' . $this->escape($item['pubDate']) . '</pubDate>' . "\n";
            }

            $xml .= '      <description>' . $this->escape($item['description']) . '</description>' . "\n"
                . '    </item>' . "\n";
        }

        return $xml . '  </channel>' . "\n" . '</rss>' . "\n";
    }

    /**
     * SitemapXml 의 escape() 보다 방어가 하나 더 필요하다 — sitemap 은 이미
     * 인코딩된 ASCII URL 과 날짜만 직렬화하지만, 이쪽은 작성자가 쓴 자유
     * 텍스트(제목·요약)를 처음으로 이 직렬화기에 태운다.
     */
    private function escape(string $value): string
    {
        // XML 1.0 이 금지하는 제어문자(탭·개행·캐리지리턴은 허용)를 먼저 없앤다.
        // 남겨 두면 htmlspecialchars 를 그대로 통과해 문서 전체가 파싱 불가가 된다.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

        // ENT_SUBSTITUTE 가 없으면 유효하지 않은 UTF-8 이 섞였을 때 htmlspecialchars
        // 가 전체를 빈 문자열로 돌려줘 엘리먼트가 조용히 비어 버린다.
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
