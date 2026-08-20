<?php

namespace Tests\Feature;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * /posts 페이지네이션 경계 테스트 — 한 페이지에 정확히 10건.
 *
 * created_at 을 통제한 글 11건을 넣고(PAGE-11 이 최신, PAGE-01 이 가장 오래됨),
 * 1페이지가 최신 10건(PAGE-02~11)만 담고 11번째(PAGE-01)는 2페이지로 넘어가는지 본다.
 * (5건/페이지였다면 PAGE-02 는 1페이지에 없고 PAGE-01 은 3페이지에 있다.)
 */
final class PostPaginationTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        // 페이저는 공유 서비스라 앞 테스트의 currentPage 가 남는다. 매번 새로.
        \Config\Services::resetSingle('pager');

        // created_at 을 직접 통제하려고 모델(useTimestamps) 대신 빌더로 넣는다.
        // 01 이 가장 오래되고 11 이 가장 최신이 되도록 분 단위로 시차를 둔다.
        $rows = [];
        for ($i = 1; $i <= 11; $i++) {
            $n            = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $rows[] = [
                'user_id'    => 1,
                'title'      => 'PAGE-' . $n,
                'slug'       => 'page-' . $n,
                'body'       => 'PAGE-' . $n . ' 본문',
                'created_at' => '2026-05-01 00:' . $n . ':00',
                'updated_at' => '2026-05-01 00:' . $n . ':00',
            ];
        }
        db_connect()->table('posts')->insertBatch($rows);
    }

    public function testFirstPageHoldsTenPosts(): void
    {
        $cards = $this->cardList($this->call('GET', 'posts')->response()->getBody());

        // 10건/페이지면 최신 10건(PAGE-02~11)이 1페이지에 있다.
        $this->assertStringContainsString('PAGE-11', $cards); // 최신
        $this->assertStringContainsString('PAGE-02', $cards); // 10번째 (5건/페이지였다면 1페이지에 없다)
        $this->assertStringNotContainsString('PAGE-01', $cards); // 11번째는 넘어간다
    }

    /** 카드 목록 영역만 잘라 낸다. */
    private function cardList(string $html): string
    {
        $start = strpos($html, '<ul class="post-list">');

        return $start === false ? '' : substr($html, $start);
    }

    public function testEleventhPostGoesToSecondPage(): void
    {
        // 2페이지에 11번째(가장 오래된 PAGE-01)가 보인다.
        // (5건/페이지였다면 2페이지는 PAGE-02~06 이라 PAGE-01 이 없다.)
        $this->call('GET', 'posts', ['page' => '2'])->assertSee('PAGE-01');
    }

    /**
     * 범위를 벗어난 page 는 404 다.
     *
     * 200 + 빈 목록은 소프트 404 다 — 검색엔진이 "내용 없는 페이지" 를 색인 후보로
     * 붙들고 있게 된다(GSC 미색인 목록에 ?page=4 가 잡혔다). 없는 페이지는 없다고
     * 답해야 한다. 글 11건 · 10건씩이므로 2페이지까지가 전부다.
     */
    public function testOutOfRangePageIsNotFound(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->call('GET', 'posts', ['page' => '3']);
    }

    /**
     * 1 미만이거나 숫자가 아닌 page 도 404 다.
     *
     * 상한만 막으면 절반만 막은 것이다. Pager 에는 하한 클램프가 따로 있어서
     * (`$page < 1 ? 1 : $page`) ?page=0 · ?page=-1 · ?page=abc 가 모두 1페이지를
     * 200 으로 돌려준다. canonical 이 /posts 를 가리키므로 색인 위험은 낮지만,
     * 200 을 주는 쓰레기 URL 이 무한히 생기고 상한과 동작이 어긋난다.
     */
    public function testNonPositivePageIsNotFound(): void
    {
        foreach (['0', '-1', '-999', 'abc', ''] as $bad) {
            try {
                $this->call('GET', 'posts', ['page' => $bad]);
                $this->fail("page='{$bad}' 가 404 가 아니다.");
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** page 를 아예 안 주면 첫 페이지다 — 위 가드가 정상 요청까지 잡지 않는지 본다. */
    public function testMissingPageParameterIsFirstPage(): void
    {
        $this->call('GET', 'posts')->assertStatus(200);
        $this->call('GET', 'posts', ['page' => '1'])->assertStatus(200);
    }

    /**
     * 글이 하나도 없어도 첫 페이지는 200 이다.
     *
     * "결과가 비었으면 404" 로 구현하면 글을 다 지운 사이트의 목록이 사라진다.
     * 없는 것은 페이지가 아니라 글이다.
     */
    public function testEmptyFirstPageIsStillOk(): void
    {
        db_connect()->table('posts')->emptyTable();

        $this->call('GET', 'posts')->assertStatus(200);
    }

    public function testPagerMarksCurrentPage(): void
    {
        // 현재 페이지는 aria-current="page" 로 표시돼 위치를 알 수 있어야 한다.
        $body = $this->call('GET', 'posts', ['page' => '2'])->getBody();
        $this->assertStringContainsString('aria-current="page"', $body);
    }

    public function testPagerUsesKoreanPrevNextLabels(): void
    {
        // 이전/다음 컨트롤은 한국어 라벨을 쓴다(기본 템플릿의 영어 Next/Last 아님).
        $res = $this->call('GET', 'posts', ['page' => '2']);
        $res->assertSee('이전');
        $res->assertSee('다음');
    }

    public function testPrevDisabledOnFirstPage(): void
    {
        // 1페이지에서는 '이전'이 비활성(disabled)이어야 한다.
        $body = $this->call('GET', 'posts')->getBody();
        $this->assertStringContainsString('page-prev disabled', $body);
        $this->assertStringNotContainsString('page-next disabled', $body); // 다음은 살아 있음
    }

    public function testNextDisabledOnLastPage(): void
    {
        // 마지막(2)페이지에서는 '다음'이 비활성이어야 한다.
        $body = $this->call('GET', 'posts', ['page' => '2'])->getBody();
        $this->assertStringContainsString('page-next disabled', $body);
        $this->assertStringNotContainsString('page-prev disabled', $body); // 이전은 살아 있음
    }
}
