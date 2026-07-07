<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * 글 목록 화면에 대한 Feature 테스트.
 *
 * 마이그레이션으로 posts 테이블을 만들고 PostSeeder로 더미 글 6건을 채운 뒤,
 * /posts 가 모델에서 읽어 온 글들을 페이지 단위로 그려 주는지 검증한다.
 * (한 페이지 10건 기준: 시더 6건은 최신·최고참 모두 1페이지에 담긴다.
 *  정확한 10-경계는 PostPaginationTest 가 따로 검증한다.)
 */
final class PostIndexTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    // 모든 네임스페이스의 마이그레이션을 매 테스트마다 새로 적용한다.
    // (ep11부터 공통 헤더가 auth()를 호출하므로 Shield/Settings 테이블도 필요하다.)
    protected $namespace = null;
    protected $refresh   = true;
    protected $seed      = 'App\Database\Seeds\PostSeeder';

    // 시더가 넣는 글 중 가장 최신/가장 오래된 글의 제목
    private const NEWEST_TITLE = 'CodeIgniter 4로 블로그 만들기를 시작하며';
    private const OLDEST_TITLE = '시더로 현실적인 더미 데이터 채우기';

    protected function setUp(): void
    {
        parent::setUp();

        // 페이저는 공유 서비스라 앞 테스트의 currentPage 가 캐시된 채 남는다.
        // 매 테스트마다 새로 만들어 페이지 계산이 서로 섞이지 않게 한다.
        \Config\Services::resetSingle('pager');
    }

    public function testIndexReturns200(): void
    {
        $this->call('GET', 'posts')->assertStatus(200);
    }

    public function testFirstPageShowsNewestPost(): void
    {
        $this->call('GET', 'posts')->assertSee(self::NEWEST_TITLE);
    }

    public function testFirstPageShowsOldestPostSinceSixFitInTen(): void
    {
        // 한 페이지 10건이면 시더 6건이 모두 1페이지에 담기므로
        // 가장 오래된 글도 1페이지에서 보여야 한다. (5건/페이지였다면 2페이지로 밀린다.)
        $this->call('GET', 'posts')->assertSee(self::OLDEST_TITLE);
    }
}
