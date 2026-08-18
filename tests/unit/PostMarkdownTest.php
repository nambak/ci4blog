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

        $this->assertStringContainsString('<th align="center">가운데</th>', $html);
        $this->assertStringContainsString('<td align="right">c</td>', $html);
    }

    public function testTableCellEscapesRawHtml(): void
    {
        // 표를 켜면서 보안 설정(html_input=escape)이 유실되면 셀이 XSS 통로가 된다.
        $html = $this->html("| 항목 |\n|---|\n| <script>alert(1)</script> |");

        $this->assertStringContainsString('<td>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testTableCellBlocksUnsafeLinks(): void
    {
        // 링크 필터도 표 안에서 그대로 살아 있어야 한다.
        $html = $this->html("| 링크 |\n|---|\n| [클릭](javascript:alert(1)) |");

        $this->assertStringContainsString('<td>', $html);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $html);
    }
}
