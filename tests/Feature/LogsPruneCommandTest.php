<?php

namespace Tests\Feature;

use App\Commands\LogsPrune;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;
use DateTimeImmutable;

/**
 * php spark logs:prune — 보존 기간을 넘긴 일자별 로그 정리.
 *
 * **실제 writable/logs 를 쓰지 않는다.** DbBackupCommandTest 는 실제
 * writable/backups 를 쓰고 tearDown 에서 지우지만, 로그에 같은 방식을 쓰면
 * 개발자의 실제 로그를 테스트가 파괴하고 남아 있는 로그 상태에 따라 결과가
 * 흔들린다. logDir() seam 을 임시 디렉터리로 갈아끼운다.
 *
 * CLI::write() 는 STDOUT 에 직접 fwrite() 하므로 출력 버퍼링을 타지 않는다 —
 * DbPruneCommandTest 와 같이 StreamFilterTrait 로 가로챈다.
 *
 * @internal
 */
final class LogsPruneCommandTest extends CIUnitTestCase
{
    use StreamFilterTrait;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = WRITEPATH . 'logs-test-' . bin2hex(random_bytes(4));
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

    /** logDir() 이 임시 디렉터리를 보게 만든 커맨드. */
    private function prune(): LogsPruneStub
    {
        $command = new LogsPruneStub(service('logger'), service('commands'));

        $command->dirOverride = $this->dir;

        return $command;
    }

    /** 지정한 날짜의 로그 파일을 만든다. */
    private function makeLog(string $date, int $bytes = 16): string
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . 'log-' . $date . '.log';

        file_put_contents($path, str_repeat('x', $bytes));

        return $path;
    }

    /** $days 일 전 날짜(파일명 형식). 커맨드와 같은 기본 타임존을 쓴다. */
    private function daysAgo(int $days): string
    {
        return (new DateTimeImmutable('today'))->modify("-{$days} days")->format('Y-m-d');
    }

    /** 디렉터리에 남아 있는 로그 파일명 목록. */
    private function remaining(): array
    {
        $files = glob($this->dir . DIRECTORY_SEPARATOR . 'log-*.log') ?: [];

        return array_map('basename', $files);
    }

    public function testScanWithoutForceDeletesNothing(): void
    {
        $old = $this->makeLog($this->daysAgo(90));

        $result = $this->prune()->run([]);

        $this->assertSame(EXIT_SUCCESS, $result);
        $this->assertFileExists($old, '--force 없이는 파일을 지우면 안 된다.');
        $this->assertSame([basename($old)], $this->remaining());
        $this->assertStringContainsString('정리 대상 1개', $this->getStreamFilterBuffer());
        $this->assertStringContainsString('--force', $this->getStreamFilterBuffer());
    }

    public function testReportsNothingToPruneWhenAllLogsAreRecent(): void
    {
        $this->makeLog($this->daysAgo(0));
        $this->makeLog($this->daysAgo(5));

        $result = $this->prune()->run([]);

        $this->assertSame(EXIT_SUCCESS, $result);
        $this->assertCount(2, $this->remaining());
        $this->assertStringContainsString('정리할 로그가 없습니다', $this->getStreamFilterBuffer());
    }
}

/**
 * 로그 디렉터리만 갈아끼운 테스트용 서브클래스.
 *
 * 실제 커맨드 로직은 그대로 돌리고 경로만 바꾼다 — 프로덕션 코드에
 * 테스트 전용 옵션(--path 같은 것)을 넣지 않기 위한 seam 이다.
 */
final class LogsPruneStub extends LogsPrune
{
    public string $dirOverride = '';

    protected function logDir(): string
    {
        return $this->dirOverride;
    }
}
