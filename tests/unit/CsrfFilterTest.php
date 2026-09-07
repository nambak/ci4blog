<?php

use App\Filters\Csrf;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Method;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Security\SecurityInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Filters as FiltersConfig;
use Config\Services;

/**
 * CSRF 필터는 전역 before 에 걸려 있어 404·400 을 포함한 모든 요청을 탄다.
 * 기본 필터는 그때마다 service('security') 를 만드는데, csrfProtection 이
 * 'session' 이면 Security 생성자가 configureSession() 으로 세션을 시작한다
 * (system/Security/Security.php:207,224). 스캐너가 없는 경로를 두드릴 때마다
 * 세션 파일이 하나씩 생긴 이유다(#179 — 운영에 37,463개).
 *
 * Security::verify() 는 POST·PUT·DELETE·PATCH 만 검증하고 나머지는 즉시
 * 반환하므로, 읽기 요청에서 security 를 만들지 않아도 잃는 보호가 없다.
 *
 * @internal
 */
final class CsrfFilterTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('security');
    }

    protected function tearDown(): void
    {
        Services::resetSingle('security');
        parent::tearDown();
    }

    /**
     * @dataProvider readMethodProvider
     */
    public function testDoesNotTouchSecurityOnReadRequests(string $method): void
    {
        $spy = $this->injectSecuritySpy();

        (new Csrf())->before($this->request($method));

        $this->assertFalse(
            $spy->verified,
            $method . ' 은 Security::verify() 가 어차피 검증하지 않는다 — security 를 만들면 세션만 생긴다.',
        );
    }

    /**
     * 대조군. 읽기 경로만 보면 필터가 통째로 죽어도 위 테스트는 통과한다.
     *
     * @dataProvider writeMethodProvider
     */
    public function testVerifiesWriteRequests(string $method): void
    {
        $spy = $this->injectSecuritySpy();

        (new Csrf())->before($this->request($method));

        $this->assertTrue($spy->verified, $method . ' 은 CSRF 검증을 그대로 받아야 한다.');
    }

    public static function readMethodProvider(): iterable
    {
        yield 'GET'     => [Method::GET];
        yield 'HEAD'    => [Method::HEAD];
        yield 'OPTIONS' => [Method::OPTIONS];
    }

    public static function writeMethodProvider(): iterable
    {
        yield 'POST'   => [Method::POST];
        yield 'PUT'    => [Method::PUT];
        yield 'PATCH'  => [Method::PATCH];
        yield 'DELETE' => [Method::DELETE];
    }

    /**
     * 위 테스트들은 필터를 직접 만들어 부른다. 별칭이 프레임워크 필터를 가리키면
     * 이 클래스는 실제 요청에서 한 번도 실행되지 않으므로 배선을 따로 확인한다.
     */
    public function testAliasPointsAtThisFilter(): void
    {
        $this->assertSame(
            Csrf::class,
            config(FiltersConfig::class)->aliases['csrf'],
            '별칭이 이 클래스를 가리켜야 실제 요청에서 동작한다.',
        );
    }

    /**
     * 세션이 덜 생기게 하겠다고 csrf 를 전역에서 빼면 CSRF 보호가 통째로 사라진다.
     * 이 필터의 요지는 "전역은 그대로 두고 읽기 요청만 비운다" 이다.
     */
    public function testCsrfStaysGlobal(): void
    {
        $this->assertArrayHasKey(
            'csrf',
            config(FiltersConfig::class)->globals['before'],
            'csrf 는 전역 before 에 남아 있어야 한다.',
        );
    }

    /** verify() 가 불렸는지만 기록하는 가짜 Security. */
    private function injectSecuritySpy(): SecurityInterface
    {
        $spy = new class implements SecurityInterface {
            public bool $verified = false;

            public function verify(\CodeIgniter\HTTP\RequestInterface $request)
            {
                $this->verified = true;

                return $this;
            }

            public function getHash(): ?string
            {
                return 'hash';
            }

            public function getTokenName(): string
            {
                return 'csrf_test_name';
            }

            public function getHeaderName(): string
            {
                return 'X-CSRF-TOKEN';
            }

            public function getCookieName(): string
            {
                return 'csrf_cookie';
            }

            public function shouldRedirect(): bool
            {
                return false;
            }

            public function sanitizeFilename(string $str, bool $relativePath = false): string
            {
                return $str;
            }
        };

        // injectMock 은 소문자 이름으로 넣어야 resetSingle 이 같은 키를 지운다.
        Services::injectMock('security', $spy);

        return $spy;
    }

    private function request(string $method): IncomingRequest
    {
        $config = new App();

        return (new IncomingRequest($config, new SiteURI($config), null, new UserAgent()))
            ->withMethod($method);
    }
}
