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

    public function testForceDeletesOldLogs(): void
    {
        $old    = $this->makeLog($this->daysAgo(90));
        $recent = $this->makeLog($this->daysAgo(1));

        $result = $this->prune()->run(['force' => null]);

        $this->assertSame(EXIT_SUCCESS, $result);
        $this->assertFileDoesNotExist($old, '90일 전 로그는 지워져야 한다.');
        $this->assertFileExists($recent, '어제 로그는 남아야 한다.');
        $this->assertSame([basename($recent)], $this->remaining());
        $this->assertStringContainsString('1개 삭제', $this->getStreamFilterBuffer());
    }

    /**
     * 경계가 이 기능의 정확성 전부다.
     *
     * --keep-days 30 = 오늘 포함 최근 30개 날짜를 남긴다
     * → 나이 29일 보존, 나이 30일 삭제.
     */
    public function testBoundaryKeepsDay29AndDeletesDay30(): void
    {
        $keep   = $this->makeLog($this->daysAgo(29));
        $delete = $this->makeLog($this->daysAgo(30));

        $this->prune()->run(['force' => null]);

        $this->assertFileExists($keep, '나이 29일 로그는 보존해야 한다.');
        $this->assertFileDoesNotExist($delete, '나이 30일 로그는 삭제해야 한다.');
    }

    /**
     * 로그 파일이 아닌 것, 이름 규약에 안 맞는 것, 존재하지 않는 날짜는
     * 아무리 오래돼 보여도 건드리지 않는다.
     */
    public function testLeavesFilesThatAreNotDatedLogs(): void
    {
        $index   = $this->dir . DIRECTORY_SEPARATOR . 'index.html';
        $badName = $this->dir . DIRECTORY_SEPARATOR . 'log-bad.log';
        $badDate = $this->dir . DIRECTORY_SEPARATOR . 'log-2026-13-45.log';

        // 존재하지 않는 날짜는 createFromFormat 이 조용히 넘긴다(경고만 남는다).
        //   '2026-13-45' → 2027-02-14 (미래로 넘어간다)
        //   '<약 4개월 전의 달>-00' → 그 전월 말일 (과거로 넘어간다)
        // 미래로 넘어가는 값은 유효성 검사를 지워도 우연히 보존되므로,
        // 방어가 실제로 동작하는지는 **과거로 넘어가는 값**이 증명한다.
        $rollsBackward = $this->dir . DIRECTORY_SEPARATOR . 'log-'
            . (new DateTimeImmutable('today'))->modify('-90 days')->format('Y-m') . '-00.log';

        file_put_contents($index, 'x');
        file_put_contents($badName, 'x');
        file_put_contents($badDate, 'x');
        file_put_contents($rollsBackward, 'x');

        $this->prune()->run(['force' => null]);

        $this->assertFileExists($index, 'index.html 은 대상이 아니다.');
        $this->assertFileExists($badName, '날짜 없는 이름은 건드리지 않는다.');
        $this->assertFileExists($badDate, '존재하지 않는 날짜(13월 45일)는 건드리지 않는다.');
        $this->assertFileExists($rollsBackward, '0일 같은 값이 과거로 넘어가도 건드리지 않는다.');
    }

    /** 서버 시계가 앞서 있어 미래 날짜 로그가 생긴 경우에도 지우지 않는다. */
    public function testLeavesFutureDatedLog(): void
    {
        $future = $this->makeLog(
            (new DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d')
        );

        $this->prune()->run(['force' => null]);

        $this->assertFileExists($future, '미래 날짜 로그는 보존해야 한다.');
    }

    public function testCustomKeepDaysIsApplied(): void
    {
        $deleted = $this->makeLog($this->daysAgo(20));
        $kept    = $this->makeLog($this->daysAgo(10));

        $result = $this->prune()->run(['force' => null, 'keep-days' => '14']);

        $this->assertSame(EXIT_SUCCESS, $result);
        $this->assertFileDoesNotExist($deleted, '--keep-days 14 면 20일 전 로그는 지워야 한다.');
        $this->assertFileExists($kept, '10일 전 로그는 남아야 한다.');
        $this->assertStringContainsString('보관 14일', $this->getStreamFilterBuffer());
    }

    /**
     * (int) 캐스팅은 '3a' 를 3 으로 조용히 받아 준다. --keep-days 3O 같은
     * 오타가 보존 기간을 3일로 만들어 로그를 통째로 날리는 사고가 되므로,
     * 숫자 문자열만 받고 나머지는 거부한다(db:backup --keep 과 같은 자리).
     */
    public function testNonNumericKeepDaysIsRejectedAndDeletesNothing(): void
    {
        $old = $this->makeLog($this->daysAgo(90));

        $result = $this->prune()->run(['force' => null, 'keep-days' => '3a']);

        $this->assertSame(EXIT_ERROR, $result);
        $this->assertFileExists($old, '입력이 잘못되면 아무것도 지우지 않아야 한다.');
        $this->assertStringContainsString('--keep-days', $this->getStreamFilterBuffer());
    }

    public function testZeroKeepDaysIsRejectedAndDeletesNothing(): void
    {
        $today = $this->makeLog($this->daysAgo(0));

        $result = $this->prune()->run(['force' => null, 'keep-days' => '0']);

        $this->assertSame(EXIT_ERROR, $result);
        $this->assertFileExists($today, '0 은 오늘 로그까지 지우게 되므로 거부해야 한다.');
    }

    /**
     * 커맨드가 등록돼 spark 로 불릴 수 있는지만 본다.
     *
     * seam 을 쓰는 다른 테스트들은 실제 writable/logs 를 보지 않으므로
     * "커맨드 이름이 틀렸다" 같은 배선 사고를 못 잡는다. --force 를 주지 않아
     * 삭제가 일어나지 않으므로 개발자의 실제 로그는 안전하다.
     */
    public function testCommandIsRegisteredAndRunsAsScan(): void
    {
        command('logs:prune');

        $output = $this->getStreamFilterBuffer();

        $this->assertMatchesRegularExpression(
            '/정리 대상 \d+개|정리할 로그가 없습니다/',
            $output,
            'logs:prune 이 등록돼 있고 스캔 결과를 출력해야 한다.'
        );
        $this->assertStringNotContainsString('삭제', $output, '배선 테스트가 파일을 지워서는 안 된다.');
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
