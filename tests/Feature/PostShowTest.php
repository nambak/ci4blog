<?php

namespace Tests\Feature;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * 글 상세 화면에 대한 Feature 테스트.
 *
 * slug 로 글 한 건을 찾아 제목과 본문을 보여 주는지,
 * 없는 slug 에는 404 를 돌려주는지 검증한다.
 */
final class PostShowTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    // ep11부터 공통 헤더가 auth()를 호출하므로 Shield/Settings 테이블도 필요하다.
    // namespace 를 null 로 두면 모든 네임스페이스의 마이그레이션이 적용된다.
    protected $namespace = null;
    protected $refresh   = true;
    protected $seed      = 'App\Database\Seeds\PostSeeder';

    // 시더가 넣는 첫 글
    private const EXISTING_SLUG = 'codeigniter4-blog-start';
    private const EXISTING_TITLE = 'CodeIgniter 4로 블로그 만들기를 시작하며';

    public function testShowExistingPostReturns200(): void
    {
        $this->call('GET', 'posts/' . self::EXISTING_SLUG)->assertStatus(200);
    }

    public function testShowDisplaysTitleAndBody(): void
    {
        $result = $this->call('GET', 'posts/' . self::EXISTING_SLUG);

        $result->assertSee(self::EXISTING_TITLE);
        // 본문 일부도 보여야 한다
        $result->assertSee('한 회차씩 쌓아 올리며');
    }

    public function testShowMissingPostThrows404(): void
    {
        // 없는 글은 PageNotFoundException(=404)을 던진다.
        $this->expectException(PageNotFoundException::class);

        $this->call('GET', 'posts/no-such-slug');
    }
}
