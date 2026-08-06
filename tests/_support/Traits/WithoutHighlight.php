<?php

namespace Tests\Support\Traits;

/**
 * 검색 결과 본문에서 <mark> 강조 태그를 걷어내는 트레잇. (#114)
 *
 * 검색어가 일치하면 그 부분이 <mark> 로 감싸지므로 제목이 쪼개진다:
 *
 *   '공개된 글'  →  '공개된 <mark>글</mark>'
 *
 * 그래서 검색 결과를 문자열로 검사하는 테스트가 두 방향으로 어긋난다.
 *
 *   - assertSee('공개된 글') 은 원문을 못 찾아 **거짓 실패**한다.
 *   - 🔴 assertDontSee('초안 상태 글') 은 쪼개짐 덕분에 **거짓 통과**한다.
 *
 * 두 번째가 훨씬 위험하다 — 초안이 실수로 검색에 노출돼도 제목이
 * '초안 상태 <mark>글</mark>' 이 되어 음성 단언이 조용히 통과하기 때문이다.
 * 즉 강조 기능이 기존 방지망을 무력화한다. 그래서 검사 전에 태그를 지운다.
 *
 * 강조로 쪼개지는 것 자체는 의도된 동작이고 화면 표시도 정상이다.
 * 태그가 제대로 붙는지는 SearchHighlightTest 가 따로 본다.
 *
 * html_entity_decode 를 함께 거치는 이유는 CI 의 ubuntu 에서 TestResponse 가
 * 한글을 숫자 엔티티로 바꾸기 때문이다.
 */
trait WithoutHighlight
{
    /** 강조 태그를 지우고 엔티티를 디코드한 본문. */
    protected function withoutHighlight(string $html): string
    {
        return html_entity_decode(
            str_replace(['<mark>', '</mark>'], '', $html),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }
}
