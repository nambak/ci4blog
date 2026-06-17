<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * 검색과 카테고리 필터의 '상태 유지'에 대한 Feature 테스트.
 *
 * - 카테고리 + 검색은 AND 로 함께 적용된다(서로를 리셋하지 않는다).
 * - 카테고리 메뉴 링크는 현재 검색어(q)를 유지한다.
 * - 검색 폼은 현재 카테고리를 유지한다(카테고리 페이지에서 검색해도 카테고리가 풀리지 않음).
 */
final class CombinedFilterTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $namespace = null;
    protected $refresh   = true;
    protected $seed      = 'App\Database\Seeds\PostSeeder';

    private const WEB_MATCH    = '공통 레이아웃으로 중복 걷어내기'; // web + '레이아웃'
    private const WEB_NONMATCH = '첫 테스트가 주는 안전망';        // web 이지만 '레이아웃' 아님

    protected function setUp(): void
    {
        parent::setUp();
        \Config\Services::resetSingle('pager');
    }

    public function testCategoryAndSearchCombineAsAnd(): void
    {
        // codeigniter4 카테고리에는 '레이아웃' 글이 없다 → 교집합이 비어 무결과여야 한다.
        // (OR 로 새면 codeigniter4 글들이 떠 버린다)
        $this->call('GET', 'categories/codeigniter4', ['q' => '레이아웃'])
            ->assertSee('검색 결과가 없습니다');
    }

    public function testCombinedShowsIntersectionOnly(): void
    {
        $res = $this->call('GET', 'categories/web', ['q' => '레이아웃']);
        $res->assertSee(self::WEB_MATCH);       // web ∩ 레이아웃
        $res->assertDontSee(self::WEB_NONMATCH); // web 이지만 검색어 불일치 → 빠짐
    }

    public function testCategoryMenuKeepsSearch(): void
    {
        // 검색 중에는 카테고리 메뉴 링크가 q 를 달고 있어야 한다(클릭해도 검색 유지).
        // 페이저(posts?q=...)가 아니라 '카테고리 링크'가 q 를 가지는지를 본다.
        $this->call('GET', 'posts', ['q' => 'Forge'])
            ->assertSee('categories/codeigniter4?q=Forge');
    }

    public function testSearchFormKeepsCategory(): void
    {
        // 카테고리 페이지의 검색 폼 action 은 그 카테고리를 가리켜야 한다(검색해도 카테고리 유지).
        // 페이지에서 action= 을 쓰는 건 검색 폼뿐이라, 핵심 계약(action 값)만 검증한다.
        $this->call('GET', 'categories/web')
            ->assertSee('action="' . site_url('categories/web') . '"');
    }
}
