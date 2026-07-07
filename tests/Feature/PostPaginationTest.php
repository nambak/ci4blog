<?php

namespace Tests\Feature;

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
        $res = $this->call('GET', 'posts');

        // 10건/페이지면 최신 10건(PAGE-02~11)이 1페이지에 있다.
        $res->assertSee('PAGE-11'); // 최신
        $res->assertSee('PAGE-02'); // 10번째 (5건/페이지였다면 1페이지에 없다)
        $res->assertDontSee('PAGE-01'); // 11번째는 넘어간다
    }

    public function testEleventhPostGoesToSecondPage(): void
    {
        // 2페이지에 11번째(가장 오래된 PAGE-01)가 보인다.
        // (5건/페이지였다면 2페이지는 PAGE-02~06 이라 PAGE-01 이 없다.)
        $this->call('GET', 'posts', ['page' => '2'])->assertSee('PAGE-01');
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
