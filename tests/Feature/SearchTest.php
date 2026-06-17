<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * 기본 검색(`/posts?q=...`)에 대한 Feature 테스트.
 *
 * 제목·본문을 Query Builder 의 like() 로 찾는다.
 * 시더 더미 글 기준:
 *  - '레이아웃' → 제목 '공통 레이아웃으로 중복 걷어내기' 만 매칭
 *  - 'Forge'   → 본문에만 있는 '마이그레이션으로 스키마를 코드로 남기기' 매칭
 */
final class SearchTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $namespace = null;
    protected $refresh   = true;
    protected $seed      = 'App\Database\Seeds\PostSeeder';

    private const LAYOUT_POST    = '공통 레이아웃으로 중복 걷어내기';
    private const MIGRATION_POST = '마이그레이션으로 스키마를 코드로 남기기';
    // 검색어와 무관하면서, 페이지네이션이 아니라 '검색' 때문에 빠져야 하는 글
    // (가장 최신 글이라 검색이 없으면 항상 1페이지에 보인다 → 제외 검증에 적합)
    private const NEWEST_POST = 'CodeIgniter 4로 블로그 만들기를 시작하며';

    protected function setUp(): void
    {
        parent::setUp();
        \Config\Services::resetSingle('pager');
    }

    public function testSearchReturns200(): void
    {
        $this->call('GET', 'posts', ['q' => '레이아웃'])->assertStatus(200);
    }

    public function testSearchMatchesTitle(): void
    {
        $this->call('GET', 'posts', ['q' => '레이아웃'])->assertSee(self::LAYOUT_POST);
    }

    public function testSearchExcludesNonMatching(): void
    {
        // '레이아웃' 으로 찾으면, 평소 1페이지에 보이던 최신 글도 빠진다(검색이 거른 것).
        $this->call('GET', 'posts', ['q' => '레이아웃'])->assertDontSee(self::NEWEST_POST);
    }

    public function testSearchMatchesBody(): void
    {
        // 'Forge' 는 본문에만 있다 → 본문 검색이 동작하고, 무관한 최신 글은 빠진다.
        $res = $this->call('GET', 'posts', ['q' => 'Forge']);
        $res->assertSee(self::MIGRATION_POST);
        $res->assertDontSee(self::NEWEST_POST);
    }

    public function testEmptyQueryShowsAllPosts(): void
    {
        // 빈 검색어는 전체 목록(최신 글)을 그대로 보여 준다.
        $this->call('GET', 'posts', ['q' => ''])->assertSee(self::NEWEST_POST);
    }

    public function testNoResultsShowsMessage(): void
    {
        $res = $this->call('GET', 'posts', ['q' => '존재하지않는검색어xyz']);
        $res->assertSee('검색 결과가 없습니다');
        $res->assertDontSee(self::LAYOUT_POST);
    }
}
