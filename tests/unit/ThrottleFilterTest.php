<?php

use App\Filters\Throttle;
use CodeIgniter\Config\Factories;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Method;
use CodeIgniter\Test\CIUnitTestCase;
use Config\RateLimit;
use Config\Services;

/**
 * Throttle 필터의 판단 로직.
 *
 * 차단(리다이렉트 생성)과 사용자 ID 키 경로는 세션·응답·DB 서비스에 얽히므로
 * Feature 테스트(RateLimitTest)가 맡는다. 여기서는 그 부팅 상태 없이 볼 수 있는
 * 경로만 본다 — 그래서 액션을 login 으로 쓴다. login 은 IP 로 키를 매기므로
 * auth()->id()(Shield·DB 의존)를 타지 않아 순수 단위 테스트로 유지된다.
 *
 * @internal
 */
final class ThrottleFilterTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        Services::resetSingle('session');
        Services::resetSingle('auth');
        Services::resetSingle('throttler');
        cache()->clean();
    }

    protected function tearDown(): void
    {
        cache()->clean();
        Factories::reset('config');
        parent::tearDown();
    }

    /**
     * 테스트용 설정을 주입한다. capacity 를 낮게 잡아 실제 횟수만큼
     * 호출하지 않고도 경계를 검증한다.
     */
    private function injectConfig(bool $enabled = true, int $capacity = 2): void
    {
        $config          = new RateLimit();
        $config->enabled = $enabled;
        $config->limits  = ['login' => ['capacity' => $capacity, 'seconds' => 60]];

        Factories::injectMock('config', 'RateLimit', $config);
    }

    private function request(string $method = Method::POST): IncomingRequest
    {
        $request = $this->createMock(IncomingRequest::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getIPAddress')->willReturn('203.0.113.9');

        return $request;
    }

    public function testPassesWithinCapacity(): void
    {
        $this->injectConfig(capacity: 2);
        $filter = new Throttle();

        $this->assertNull($filter->before($this->request(), ['login']));
        $this->assertNull($filter->before($this->request(), ['login']));
    }

    public function testGetRequestIsNotCounted(): void
    {
        $this->injectConfig(capacity: 1);
        $filter = new Throttle();

        // GET 을 여러 번 보내도 토큰이 줄지 않아야 한다.
        $filter->before($this->request(Method::GET), ['login']);
        $filter->before($this->request(Method::GET), ['login']);

        $this->assertNull($filter->before($this->request(), ['login']));
    }

    public function testDisabledSwitchPassesEverything(): void
    {
        $this->injectConfig(enabled: false, capacity: 1);
        $filter = new Throttle();

        $filter->before($this->request(), ['login']);
        $filter->before($this->request(), ['login']);

        $this->assertNull($filter->before($this->request(), ['login']));
    }

    public function testUnknownActionThrows(): void
    {
        $this->injectConfig();
        $filter = new Throttle();

        $this->expectException(InvalidArgumentException::class);
        $filter->before($this->request(), ['oops']);
    }

    public function testMissingActionArgumentThrows(): void
    {
        $this->injectConfig();
        $filter = new Throttle();

        $this->expectException(InvalidArgumentException::class);
        $filter->before($this->request(), null);
    }
}
