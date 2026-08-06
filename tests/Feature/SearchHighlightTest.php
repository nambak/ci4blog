<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * 검색어 하이라이트에 대한 테스트. (#114)
 *
 * 이 기능의 위험은 전부 이스케이프 순서에 있다. <mark> 를 넣는다는 것은 뷰에서
 * 이스케이프를 풀고 HTML 을 출력한다는 뜻이므로, 순서를 틀리면 곧바로 XSS 다.
 * 계약은 이렇다: 평문 → esc() → esc 된 검색어로 매칭 → <mark> 삽입.
 */
final class SearchHighlightTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper('search');
    }

    public function testHighlightWrapsMatchInMark(): void
    {
        $this->assertSame(
            '마이그레이션 <mark>연습</mark> 문서',
            highlight_matches('마이그레이션 연습 문서', '연습')
        );
    }

    public function testHighlightLeavesTextAloneWhenQueryIsEmpty(): void
    {
        $html = highlight_matches('마이그레이션 연습 문서', '');

        $this->assertSame('마이그레이션 연습 문서', $html);
        $this->assertStringNotContainsString('<mark>', $html);
    }

    /**
     * 🔴 XSS — 검색어로도 본문으로도 스크립트가 실행되면 안 된다.
     *
     * 본문의 <script> 는 esc 로 &lt;script&gt; 가 되고, 검색어도 같은 방식으로
     * 이스케이프해서 매칭하므로 강조는 정상 동작하되 태그로는 살아나지 않는다.
     */
    public function testHighlightEscapesHtmlInBothTextAndQuery(): void
    {
        $html = highlight_matches('위험한 <script>alert(1)</script> 조각', '<script>');

        // 먼저 강조가 실제로 일어났음을 고정한다(음성 단언만 두면 함수가 죽어도 통과한다).
        $this->assertStringContainsString('<mark>', $html);
        // 태그로 살아난 것이 없어야 한다.
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * 🔴 검색어에 정규식 메타문자가 있어도 패턴이 깨지지 않아야 한다.
     * preg_quote 가 빠지면 경고가 나거나 엉뚱한 곳이 매칭된다.
     */
    public function testHighlightHandlesRegexMetacharacters(): void
    {
        $this->assertSame(
            'C++ 와 <mark>a(b)c</mark> 예시',
            highlight_matches('C++ 와 a(b)c 예시', 'a(b)c')
        );

        // '.' 는 정규식에서 아무 문자나 뜻한다 — quote 되지 않으면 'axc' 도 걸린다.
        $this->assertStringNotContainsString('<mark>', highlight_matches('axc', 'a.c'));
    }

    public function testHighlightIgnoresCaseLikeTheDatabase(): void
    {
        $html = highlight_matches('CodeIgniter 4 입문', 'codeigniter');

        $this->assertStringContainsString('<mark>CodeIgniter</mark>', $html);
    }

    /**
     * 🔴 한글이 바이트 단위로 쪼개지면 안 된다.
     * /u 플래그가 빠지면 매칭 위치가 어긋나 깨진 문자가 나온다.
     */
    public function testHighlightKeepsKoreanIntact(): void
    {
        $html = highlight_matches('테스트와 마이그레이션 이야기', '마이그레이션');

        $this->assertStringContainsString('<mark>마이그레이션</mark>', $html);
        // 깨진 바이트가 섞이면 원문이 그대로 보존되지 않는다.
        $this->assertSame('테스트와 <mark>마이그레이션</mark> 이야기', $html);
    }

    public function testSnippetFallsBackToTheHeadWhenQueryIsEmpty(): void
    {
        $text = str_repeat('가', 100);

        $this->assertSame(str_repeat('가', 80) . '…', search_snippet($text, '', 80));
    }

    public function testSnippetFallsBackToTheHeadWhenQueryIsNotInBody(): void
    {
        $text = str_repeat('가', 100);

        // 제목만 매칭돼 본문에는 검색어가 없는 경우다.
        $this->assertSame(str_repeat('가', 80) . '…', search_snippet($text, '없는말', 80));
    }

    /**
     * 검색어가 본문 뒷부분에 있으면 그 주변을 잘라 온다.
     * 앞을 잘랐으면 '…' 이 붙고, 검색어가 스니펫 안에 실제로 들어 있어야 한다.
     */
    public function testSnippetCentersOnTheQuery(): void
    {
        $text = str_repeat('앞', 200) . '마이그레이션' . str_repeat('뒤', 200);

        $snippet = search_snippet($text, '마이그레이션', 80);

        $this->assertStringContainsString('마이그레이션', $snippet);
        $this->assertStringStartsWith('…', $snippet);
        $this->assertStringEndsWith('…', $snippet);
        // 앞 80자를 그대로 자른 것이 아니어야 한다.
        $this->assertStringNotContainsString(str_repeat('앞', 80), $snippet);
    }

    /**
     * 검색어가 두 번 나오면 첫 번째 등장 주변을 보여 준다.
     *
     * 후보를 하나만 두면 "검색어 위치를 찾는다"와 "그냥 앞에서 자른다"가
     * 구분되지 않는다(이어읽기 #141 에서 겪은 함정).
     */
    public function testSnippetUsesTheFirstOccurrence(): void
    {
        $text = str_repeat('앞', 100) . '표적' . str_repeat('중', 100) . '표적' . str_repeat('뒤', 100);

        $snippet = search_snippet($text, '표적', 80);

        // 첫 번째 '표적' 을 잡았다면 앞 문맥은 '앞', 뒤는 '중' 이다.
        // 두 번째를 잡았다면 앞이 '중', 뒤가 '뒤' 가 된다 — '뒤' 의 유무로 갈린다.
        $this->assertStringContainsString('앞', $snippet);
        $this->assertStringNotContainsString('뒤', $snippet);
    }
}
