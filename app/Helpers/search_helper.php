<?php

/**
 * 검색어 하이라이트 헬퍼. (#114)
 *
 * 🔴 이스케이프 순서가 이 파일의 전부다.
 *
 *   평문 → esc() → "esc 된 검색어" 로 매칭 → <mark> 삽입
 *
 * 검색어도 이스케이프한 뒤에 매칭하는 이유가 둘 다 실재한다:
 *   - 검색어 `<b>` 를 그대로 찾으면, 이미 `&lt;b&gt;` 가 된 본문과 매칭되지 않는다.
 *   - 이스케이프 전에 <mark> 를 넣으면 그 태그까지 이스케이프돼 화면에 글자로 보인다.
 *
 * HTML 을 반환하는 것은 highlight_matches() 하나뿐이다. search_snippet() 은
 * 평문만 다룬다 — 이스케이프 지점을 한 곳에 모아야 검토가 쉽다.
 */

if (! function_exists('search_snippet')) {
    /**
     * 검색어 주변을 잘라낸 평문을 돌려준다.
     *
     * 검색어가 비었거나 본문에 없으면(제목만 매칭된 경우) 기존처럼 앞에서 자른다.
     * 검색어 앞에 limit/4 만큼 문맥을 남겨 단어가 왼쪽 끝에 딱 붙지 않게 한다.
     */
    function search_snippet(string $text, string $query, int $limit = 80): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        $position = $query === '' ? false : mb_stripos($text, $query);
        $start    = $position === false ? 0 : max(0, $position - intdiv($limit, 4));

        $snippet = mb_substr($text, $start, $limit);

        if ($start > 0) {
            $snippet = '…' . $snippet;
        }

        if ($start + $limit < mb_strlen($text)) {
            $snippet .= '…';
        }

        return $snippet;
    }
}

if (! function_exists('highlight_matches')) {
    /**
     * 평문을 이스케이프하고 검색어와 일치하는 부분을 <mark> 로 감싼다.
     *
     * preg_quote 가 없으면 검색어의 `.` `*` `(` 가 정규식으로 해석돼 패턴이 깨지거나
     * 엉뚱한 곳이 매칭된다. /u 가 없으면 한글이 바이트 단위로 쪼개진다. /i 는 DB
     * like 가 대소문자를 무시하는 것과 화면을 맞추기 위함이다.
     */
    function highlight_matches(string $text, string $query): string
    {
        $escaped = (string) esc($text);

        if ($query === '') {
            return $escaped;
        }

        $needle = (string) esc($query);

        if ($needle === '') {
            return $escaped;
        }

        return preg_replace(
            '/' . preg_quote($needle, '/') . '/iu',
            '<mark>$0</mark>',
            $escaped
        ) ?? $escaped;
    }
}
