<?php

namespace Tests\Feature;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * 공개 페이지는 HEAD 요청에도 GET 과 같은 상태 코드를 내야 한다.
 *
 * CI4 는 HEAD 요청을 GET 라우트로 폴백하지 않는다(RouteCollection::getRoutes()
 * 는 요청 verb 배열과 '*' 만 본다). get() 으로만 등록하면 업타임 감시 봇의
 * HEAD 헬스체크가 전부 404 를 받는다(#174).
 *
 * @internal
 */
final class HeadRequestTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh   = true;
    protected $seed      = 'App\\Database\\Seeds\\PostSeeder';

    public static function publicPathProvider(): iterable
    {
        yield 'home'    => ['/'];
        yield 'about'   => ['about'];
        yield 'health'  => ['health'];
        yield 'posts'   => ['posts'];
        yield 'feed'    => ['feed'];
        yield 'sitemap' => ['sitemap.xml'];
    }

    /**
     * @dataProvider publicPathProvider
     */
    public function testHeadReturnsSameStatusAsGet(string $path): void
    {
        $get = $this->call('GET', $path);
        $get->assertStatus(200);

        $head = $this->call('HEAD', $path);

        $head->assertStatus(200);
    }

    /**
     * 플레이스홀더가 든 공개 라우트도 HEAD 로 열려야 한다. 크롤러가 실제로
     * 두드리는 건 목록이 아니라 글 주소다.
     */
    public function testHeadWorksOnPlaceholderRoutes(): void
    {
        $this->call('GET', 'posts/codeigniter4-blog-start')->assertStatus(200);

        $this->call('HEAD', 'posts/codeigniter4-blog-start')->assertStatus(200);
    }

    /**
     * 로그인이 필요한 라우트에는 HEAD 를 열지 않는다. 공개 라우트에 HEAD 를
     * 붙이면서 GET 라우트를 통째로 복제하면 필터를 놓치기 쉬운데, 그러면
     * 인증 뒤에 있어야 할 자리가 상태 코드로 드러난다.
     *
     * @dataProvider guardedPathProvider
     */
    public function testHeadIsNotOpenedOnGuardedRoutes(string $path): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->call('HEAD', $path);
    }

    public static function guardedPathProvider(): iterable
    {
        yield 'write form' => ['posts/new'];
        yield 'profile'    => ['profile'];
        yield 'admin'      => ['admin'];
    }
}
