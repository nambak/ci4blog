<?php

namespace Tests\Feature;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * 카테고리 필터(`categories/{slug}`)에 대한 Feature 테스트.
 *
 * PostSeeder 가 카테고리 3개와 더미 글 6건을 채우고 글을 카테고리에 연결한다.
 * (codeigniter4=3글, web=2글, retrospect=1글)
 * 필터 페이지가 해당 카테고리의 글만 보여 주고 다른 카테고리 글은 감추는지,
 * 없는 카테고리는 404 인지, 목록에 분류 메뉴가 그려지는지 검증한다.
 */
final class CategoryFilterTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    // 공통 헤더가 auth() 를 호출하므로 전체 마이그레이션이 필요하다.
    protected $namespace = null;
    protected $refresh   = true;
    protected $seed      = 'App\Database\Seeds\PostSeeder';

    private const WEB_POST_A    = '공통 레이아웃으로 중복 걷어내기';        // web
    private const WEB_POST_B    = '첫 테스트가 주는 안전망';                // web
    private const CI4_POST      = '라우팅과 컨트롤러, 요청은 어디로 흐르는가'; // codeigniter4
    private const RETRO_POST    = '시더로 현실적인 더미 데이터 채우기';      // retrospect

    protected function setUp(): void
    {
        parent::setUp();

        // 페이저는 공유 서비스라 앞 테스트의 currentPage 가 남는다. 매번 새로.
        \Config\Services::resetSingle('pager');
    }

    public function testCategoryPageReturns200(): void
    {
        $this->call('GET', 'categories/web')->assertStatus(200);
    }

    public function testShowsOnlyPostsInThatCategory(): void
    {
        $res = $this->call('GET', 'categories/web');
        $res->assertSee(self::WEB_POST_A);
        $res->assertSee(self::WEB_POST_B);
    }

    public function testExcludesPostsFromOtherCategories(): void
    {
        $res = $this->call('GET', 'categories/web');
        $res->assertDontSee(self::CI4_POST);   // codeigniter4 글은 빠진다
        $res->assertDontSee(self::RETRO_POST); // retrospect 글도 빠진다
    }

    public function testRetrospectCategoryShowsItsPost(): void
    {
        $this->call('GET', 'categories/retrospect')->assertSee(self::RETRO_POST);
    }

    public function testUnknownCategoryReturns404(): void
    {
        // 404 는 Feature 테스트에서 응답으로 변환되지 않고 예외로 전파된다.
        $this->expectException(PageNotFoundException::class);
        $this->call('GET', 'categories/no-such-category');
    }

    public function testListPageRendersCategoryMenu(): void
    {
        // 분류 메뉴가 카테고리 이름을 노출한다.
        $res = $this->call('GET', 'posts');
        $res->assertSee('CodeIgniter 4');
        $res->assertSee('웹 개발');
        $res->assertSee('회고');
    }
}
