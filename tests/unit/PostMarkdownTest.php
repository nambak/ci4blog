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
}
