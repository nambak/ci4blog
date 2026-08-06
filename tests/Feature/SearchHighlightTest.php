<?php

namespace Tests\Feature;

use App\Entities\Post;
use App\Models\PostModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * 검색어 하이라이트에 대한 테스트. (#114)
 *
 * 이 기능의 위험은 전부 이스케이프 순서에 있다. <mark> 를 넣는다는 것은 뷰에서
 * 이스케이프를 풀고 HTML 을 출력한다는 뜻이므로, 순서를 틀리면 곧바로 XSS 다.
 * 계약은 이렇다: 평문 → esc() → esc 된 검색어로 매칭 → <mark> 삽입.
 */
final class SearchHighlightTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

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
     * 한글이 온전히 보존되는지 본다.
     *
     * ⚠️ 이 테스트는 특정 방어 장치를 지키지 않는다 — `/u` 를 빼도 죽지 않는 것을
     * 뮤테이션으로 확인했다(패턴이 preg_quote 를 거친 리터럴뿐이라 바이트 시퀀스가
     * 그대로 일치한다). 회귀 방지용으로 남긴다: 구현이 패턴 조립 방식을 바꾸거나
     * 메타문자를 쓰게 되면 여기서 걸린다.
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

    private function makePost(string $title, string $body): int
    {
        $posts = model(PostModel::class);
        $posts->insert([
            'user_id'     => null,
            'category_id' => null,
            'title'       => $title,
            'body'        => $body,
            'status'      => Post::STATUS_PUBLISHED,
        ]);

        return (int) $posts->getInsertID();
    }

    /** 검색 결과 화면의 HTML. 한글 검사를 위해 엔티티를 디코드한다. */
    private function searchPage(string $query): string
    {
        return html_entity_decode(
            $this->call('get', 'posts', ['q' => $query])->getBody(),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }

    public function testSearchResultHighlightsTitleAndBody(): void
    {
        $this->makePost(
            '마이그레이션 첫걸음',
            str_repeat('앞부분 문장입니다. ', 30) . '여기서 마이그레이션 을 다룬다.'
        );

        $html = $this->searchPage('마이그레이션');

        // 제목이 강조된다.
        $this->assertStringContainsString('<mark>마이그레이션</mark>', $html);
        // 본문 뒷부분이 스니펫으로 올라온다 — 앞 80자에는 없던 문장이다.
        $this->assertStringContainsString('여기서', $html);
    }

    public function testListWithoutQueryHasNoMarks(): void
    {
        $this->makePost('마이그레이션 첫걸음', '본문입니다.');

        $html = html_entity_decode(
            $this->call('get', 'posts')->getBody(),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        // 화면이 실제로 그려졌음을 먼저 고정한다.
        $this->assertStringContainsString('마이그레이션 첫걸음', $html);
        $this->assertStringNotContainsString('<mark>', $html);
    }

    /**
     * 🔴 제목에 든 스크립트가 강조를 거쳐도 태그로 살아나면 안 된다.
     *
     * 벡터는 본문이 아니라 **제목**이다 — 본문은 getBodyText() 가 strip_tags 를
     * 거치므로 <script> 가 이미 사라지지만, 제목은 그 과정을 타지 않는다.
     * 그래서 제목에 스크립트를 심고, 그 제목이 실제로 매칭되는 검색어를 쓴다.
     *
     * TestResponse::getBody() 는 DOMDocument 를 거쳐 본문을 바꾸므로 이스케이프
     * 판정에 쓸 수 없다. response()->getBody() 로 원본을 봐야 한다.
     */
    public function testTitleScriptIsEscapedEvenWhenHighlighted(): void
    {
        $this->makePost('<script>alert(1)</script> 위험한 제목', '본문입니다.');

        $result = $this->call('get', 'posts', ['q' => 'script']);
        $raw    = $result->response()->getBody();

        // 먼저 강조가 실제로 일어났음을 고정한다(음성 단언만 두면 화면이 비어도 통과한다).
        $this->assertStringContainsString('<mark>', $raw);

        // 핵심: 제목의 스크립트가 태그로 살아나지 않았다.
        $this->assertStringNotContainsString('<script>alert(1)</script>', $raw);

        // 이스케이프된 형태로 남아 있다. 검색어 'script' 가 강조되므로 &lt; 와 &gt; 사이에
        // <mark> 가 끼어든다 — 그래서 '&lt;script&gt;' 는 연속 문자열로 존재하지 않는다.
        $this->assertStringContainsString('&lt;<mark>script</mark>&gt;', $raw);
    }
}
