<?php

namespace App\Libraries;

/**
 * sitemap 항목 배열을 sitemaps.org 0.9 XML 로 직렬화한다. (#124)
 *
 * DB·프레임워크에 의존하지 않는 순수 변환이다. 뷰 파일로 만들지 않은 이유는
 * <?xml 선언 앞에 공백 한 바이트만 새어도 파싱이 통째로 깨지기 때문이다 —
 * 이 저장소의 뷰는 전부 레이아웃 상속이라 그 위험이 특히 크다.
 */
class SitemapXml
{
    public const NAMESPACE_URI = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /**
     * @param list<array{loc: string, lastmod?: string|null}> $entries
     */
    public function render(array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="' . self::NAMESPACE_URI . '">' . "\n";

        foreach ($entries as $entry) {
            $xml .= '  <url>' . "\n"
                . '    <loc>' . $this->escape($entry['loc']) . '</loc>' . "\n";

            // 값이 없으면 태그 자체를 뺀다. 빈 <lastmod></lastmod> 는 규격 위반이다.
            if (isset($entry['lastmod']) && $entry['lastmod'] !== '') {
                $xml .= '    <lastmod>' . $this->escape($entry['lastmod']) . '</lastmod>' . "\n";
            }

            $xml .= '  </url>' . "\n";
        }

        return $xml . '</urlset>' . "\n";
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
