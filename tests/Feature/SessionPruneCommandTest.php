<?php

namespace Tests\Feature;

use App\Commands\SessionPrune;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * php spark session:prune — 만료된 파일 세션 정리.
 *
 * 전역 CSRF 필터가 404 요청에도 세션을 만들어 운영에 37,463개가 쌓였다(#179).
 * 원인은 App\Filters\Csrf 로 막았지만, 이미 쌓인 것과 정상 세션의 만료분은
 * 누군가 지워야 한다 — Debian/Ubuntu 의 PHP 는 session.gc_probability 가 0 이라
 * 요청 중 GC 가 돌지 않고, 배포된 cron 은 CI4 의 커스텀 savePath 를 모른다.
 *
 * LogsPruneCommandTest 와 같은 이유로 실제 writable/session 을 쓰지 않는다 —
 * 개발자의 로그인 세션을 테스트가 날리면 안 된다. sessionDir() seam 을 쓴다.
 *
 * @internal
 */
final class SessionPruneCommandTest extends CIUnitTestCase
{
    use StreamFilterTrait;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = WRITEPATH . 'session-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);

        parent::tearDown();
    }

    public function testDeletesExpiredSessionsWithForce(): void
    {
        $stale = $this->makeSession('ci_session' . str_repeat('a', 32), 10000);
        $fresh = $this->makeSession('ci_session' . str_repeat('b', 32), 60);

        $this->prune()->run(['force' => null]);

        $this->assertFileDoesNotExist($stale, '만료된 세션은 지워져야 한다.');
        $this->assertFileExists($fresh, '아직 유효한 세션은 남아야 한다.');
    }

    public function testWithoutForceOnlyCounts(): void
    {
        $stale = $this->makeSession('ci_session' . str_repeat('c', 32), 10000);

        $this->prune()->run([]);

        $this->assertFileExists($stale, '--force 없이는 지우지 않는다.');
        $this->assertStringContainsString('1', $this->getStreamFilterBuffer());
    }

    /**
     * 세션 디렉터리에는 CI4 가 두는 index.html 이 함께 있다. 패턴을 넓게 잡으면
     * 그것까지 지운다.
     */
    public function testLeavesNonSessionFilesAlone(): void
    {
        $guard = $this->dir . DIRECTORY_SEPARATOR . 'index.html';
        file_put_contents($guard, 'x');
        touch($guard, time() - 10000);

        $this->prune()->run(['force' => null]);

        $this->assertFileExists($guard, 'ci_session 패턴이 아닌 파일은 건드리지 않는다.');
    }

    /**
     * expiration 이 0 이면 "브라우저를 닫을 때까지" 라는 뜻이라 파일 나이로
     * 만료를 판정할 수 없다. 그대로 계산하면 모든 세션이 만료로 잡혀 로그인한
     * 사용자가 전부 튕긴다.
     */
    public function testRefusesToRunWhenExpirationIsZero(): void
    {
        $alive = $this->makeSession('ci_session' . str_repeat('d', 32), 10000);

        $command                = $this->prune();
        $command->ttlOverride   = 0;

        $result = $command->run(['force' => null]);

        $this->assertSame(EXIT_ERROR, $result);
        $this->assertFileExists($alive, 'expiration 을 모르면 아무것도 지우지 않아야 한다.');
    }

    /** sessionDir() 이 임시 디렉터리를 보게 만든 커맨드. */
    private function prune(): SessionPruneStub
    {
        $command = new SessionPruneStub(service('logger'), service('commands'));

        $command->dirOverride = $this->dir;

        return $command;
    }

    /** $ageSeconds 초 전에 마지막으로 쓴 세션 파일. */
    private function makeSession(string $name, int $ageSeconds): string
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, 'session payload');
        touch($path, time() - $ageSeconds);

        return $path;
    }
}

final class SessionPruneStub extends SessionPrune
{
    public string $dirOverride = '';
    public ?int $ttlOverride   = null;

    protected function sessionDir(): string
    {
        return $this->dirOverride;
    }

    protected function expiration(): int
    {
        return $this->ttlOverride ?? parent::expiration();
    }
}
