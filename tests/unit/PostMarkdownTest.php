<?php

use App\Entities\Post;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Post 엔티티의 마크다운 → HTML 변환(body_html) 단위 테스트.
 *
 * 저장은 마크다운 원문, 표시는 변환된 HTML.
 * 사용자가 쓴 본문이므로 원시 HTML/위험 링크는 막아야 한다(XSS).
 *
 * @internal
 */
final class PostMarkdownTest extends CIUnitTestCase
{
    private function html(string $body): string
    {
        return (new Post(['body' => $body]))->body_html;
    }

    public function testRendersHeading(): void
    {
        $this->assertStringContainsString('<h1>제목</h1>', $this->html('# 제목'));
    }

    public function testRendersBold(): void
    {
        $this->assertStringContainsString('<strong>굵게</strong>', $this->html('**굵게**'));
    }

    public function testEscapesRawHtml(): void
    {
        // 본문에 박힌 원시 <script> 는 실행 가능한 태그로 새어 나오면 안 된다.
        $html = $this->html('안녕 <script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testBlocksUnsafeLinks(): void
    {
        // javascript: 스킴 링크는 href 로 살아 남으면 안 된다.
        $html = $this->html('[클릭](javascript:alert(1))');
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function testBlocksUnsafeLinksWithMixedCaseScheme(): void
    {
        // 대소문자를 섞어 우회하려는 스킴(JaVaScRiPt:)도 막혀야 한다.
        $html = $this->html('[클릭](JaVaScRiPt:alert(1))');
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $html);
    }

    public function testBlocksDataUriScheme(): void
    {
        // data: 스킴(인라인 HTML 주입 벡터)도 href 로 남으면 안 된다.
        $html = $this->html('[클릭](data:text/html,<script>alert(1)</script>)');
        $this->assertStringNotContainsString('data:text/html', $html);
    }

    public function testRendersTable(): void
    {
        // GFM 표. CommonMark 표준에는 없는 확장이라 켜 두지 않으면 문단으로 흘러나온다(#150).
        $html = $this->html("| 항목 | 값 |\n|---|---|\n| 제목 | 테스트 |");

        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<th>항목</th>', $html);
        $this->assertStringContainsString('<td>테스트</td>', $html);
        // 파싱에 실패하면 파이프가 본문에 그대로 남는다.
        $this->assertStringNotContainsString('|---|', $html);
    }

    public function testRendersTableAlignment(): void
    {
        // 정렬 지정(:---:, ---:)은 align 속성으로 나온다. CSS 가 th 에 text-align 을
        // 무조건 주면 이 속성이 덮여 무력화되므로(속성이 author CSS 보다 약하다),
        // app.css 는 :not([align]) 으로 피한다.
        $html = $this->html("| 왼쪽 | 가운데 | 오른쪽 |\n|:---|:---:|---:|\n| a | b | c |");

        $this->assertStringContainsString('<th align="left">왼쪽</th>', $html);
        $this->assertStringContainsString('<th align="center">가운데</th>', $html);
        $this->assertStringContainsString('<td align="right">c</td>', $html);
    }

    public function testTableCellEscapesRawHtml(): void
    {
        // 표를 켜면서 보안 설정(html_input=escape)이 유실되면 셀이 XSS 통로가 된다.
        $html = $this->html("| 항목 |\n|---|\n| <script>alert(1)</script> |");

        // 셀이 통째로 비어도 아래 두 단언은 만족한다. 그래서 이스케이프된 원문이
        // 그대로 남아 있는지까지 본다 — 내용이 사라진 것은 이스케이프가 아니다.
        $this->assertStringContainsString('<td>&lt;script&gt;alert(1)&lt;/script&gt;</td>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testTableCellBlocksUnsafeLinks(): void
    {
        // 링크 필터도 표 안에서 그대로 살아 있어야 한다.
        $html = $this->html("| 링크 |\n|---|\n| [클릭](javascript:alert(1)) |");

        // href 만 떨어져 나가고 링크 텍스트는 남아야 한다. 텍스트까지 사라지면
        // 독자가 무슨 링크였는지조차 알 수 없다.
        $this->assertStringContainsString('클릭', $html);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $html);
        // 스킴 문자열만 보면 다른 위험 스킴으로 바뀌었을 때 놓친다.
        // 실제 계약은 "위험 링크는 href 가 통째로 떨어져 나간다" 이므로 그걸 직접 본다.
        $this->assertDoesNotMatchRegularExpression('/<a\b[^>]*\bhref\s*=/i', $html);
    }

    public function testWrapsTableInFocusableScrollContainer(): void
    {
        // 넓은 표는 가로로 스크롤된다. 그 스크롤 영역이 포커스를 못 받으면
        // 키보드만 쓰는 사용자는 넘친 열을 영영 볼 수 없다(WCAG 2.1.1).
        // Safari 는 스크롤 컨테이너에 포커스를 주지 않고, Chrome 도 표 안에
        // 링크가 있으면 주지 않으므로 tabindex 를 명시해야 한다.
        $html = $this->html("| 항목 | 값 |\n|---|---|\n| 제목 | 테스트 |");

        // 속성을 문서 전체에서 따로 찾으면, 엉뚱한 요소에 붙어 있어도 통과한다.
        // 네 속성이 **같은 div** 에 있고 표가 그 안에 들어가는지를 한 번에 본다
        // (lookahead 라 속성 순서에는 영향받지 않는다).
        $this->assertMatchesRegularExpression(
            '/<div(?=[^>]*\bclass="table-scroll")(?=[^>]*\btabindex="0")'
            . '(?=[^>]*\brole="region")(?=[^>]*\baria-label="표")[^>]*>\s*<table>/u',
            $html
        );
    }

    public function testDoesNotWrapNonTableContent(): void
    {
        // 데코레이터가 표 노드에만 걸려야 한다. 문단·제목까지 감싸면
        // 랜드마크가 남발되어 스크린리더 탐색이 오히려 나빠진다.
        $html = $this->html("# 제목\n\n본문 문단입니다.");

        $this->assertStringNotContainsString('table-scroll', $html);
        $this->assertStringNotContainsString('role="region"', $html);
    }
}
