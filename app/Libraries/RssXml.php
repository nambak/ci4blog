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
     * @param list<array{title: string, link: string, guid: string, pubDate: string, description: string}>          $items
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
                . '      <guid isPermaLink="false">' . $this->escape($item['guid']) . '</guid>' . "\n"
                . '      <pubDate>' . $this->escape($item['pubDate']) . '</pubDate>' . "\n"
                . '      <description>' . $this->escape($item['description']) . '</description>' . "\n"
                . '    </item>' . "\n";
        }

        return $xml . '  </channel>' . "\n" . '</rss>' . "\n";
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
