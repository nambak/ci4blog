<?php

namespace Tests\Feature;

use App\Commands\DbBackup;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;
use SQLite3;

/**
 * php spark db:backup — 마이그레이션 전 SQLite 스냅샷.
 *
 * "파일이 생겼다"만 보면 빈 파일도 통과하므로, 만들어진 백업을 새 SQLite
 * 연결로 열어 원본 데이터가 들어 있는지까지 확인한다.
 *
 * command() 는 CLI::write() 출력을 잡지 못한다(STDOUT 직접 쓰기) —
 * DbPruneCommandTest 와 같이 StreamFilterTrait 로 가로챈다.
 *
 * @internal
 */
final class DbBackupCommandTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $namespace = null;
    protected $refresh   = true;
    protected $seed      = 'App\Database\Seeds\PostSeeder';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = WRITEPATH . 'backups';
        $this->clearBackups();
    }

    protected function tearDown(): void
    {
        // 백업 파일이 다음 테스트로 새지 않게 지운다.
        $this->clearBackups();
        parent::tearDown();
    }

    private function clearBackups(): void
    {
        foreach ($this->backups() as $file) {
            @unlink($file);
        }
    }

    /** 이름 오름차순(= 오래된 것부터) 백업 파일 목록. */
    private function backups(): array
    {
        $files = glob($this->dir . DIRECTORY_SEPARATOR . 'backup-*.sqlite') ?: [];
        sort($files, SORT_STRING);

        return $files;
    }

    public function testBackupFileContainsCurrentData(): void
    {
        command('db:backup');

        $files = $this->backups();
        $this->assertCount(1, $files, '백업 파일이 1개 만들어져야 한다.');

        $backup = $files[0];
        $this->assertGreaterThan(0, filesize($backup), '백업 파일이 비어 있으면 안 된다.');

        // 파일 존재만으로는 부족하다 — 백업본을 열어 원본 데이터를 확인한다.
        $table  = db_connect()->prefixTable('posts');
        $copy   = new SQLite3($backup);
        $rows   = (int) $copy->querySingle("SELECT COUNT(*) FROM {$table}");
        $titles = [];
        $result = $copy->query("SELECT title FROM {$table}");

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $titles[] = $row['title'];
        }
        $copy->close();

        $expected = db_connect()->table('posts')->countAllResults();
        $this->assertSame($expected, $rows, '백업본의 글 수가 원본과 같아야 한다.');
        $this->assertContains(
            'CodeIgniter 4로 블로그 만들기를 시작하며',
            $titles,
            '백업본에 시더가 넣은 글 제목이 들어 있어야 한다.'
        );
    }

    public function testReportsSuccessToStdout(): void
    {
        command('db:backup');

        $this->assertStringContainsString('백업 완료', $this->getStreamFilterBuffer());
    }

    public function testKeepsOnlyNewestBackupsAndDeletesOldest(): void
    {
        command('db:backup --keep 2');
        command('db:backup --keep 2');
        command('db:backup --keep 2');

        $files = $this->backups();  // 오래된 것 → 최신 순
        $this->assertCount(2, $files, '--keep 2 면 2개만 남아야 한다.');

        // 개수만 세면 "최신 2개를 지우고 오래된 1개를 남겼다"도 통과한다.
        // 3회차 실행 직전 목록과 비교해 사라진 것이 가장 오래된 것인지 본다.
        $this->clearBackups();

        command('db:backup --keep 2');
        command('db:backup --keep 2');
        $beforeThird = $this->backups();
        $this->assertCount(2, $beforeThird);

        command('db:backup --keep 2');
        $afterThird = $this->backups();

        $this->assertNotContains(
            $beforeThird[0],
            $afterThird,
            '가장 오래된 백업이 지워져야 한다.'
        );
        $this->assertContains(
            $beforeThird[1],
            $afterThird,
            '두 번째로 오래된 백업은 남아야 한다.'
        );
    }

    public function testInvalidKeepFails(): void
    {
        command('db:backup --keep 0');

        $this->assertSame([], $this->backups(), '--keep 이 잘못되면 백업을 만들지 않는다.');
        $this->assertStringContainsString('--keep', $this->getStreamFilterBuffer());
    }

    /**
     * (int) 캐스팅만 하면 '2abc' 가 2, '1.5' 가 1 로 통과한다.
     * --keep 1O 같은 오타가 보관 개수를 1 로 만들어 백업을 지우는 사고가 된다.
     *
     * @dataProvider malformedKeepProvider
     */
    public function testMalformedKeepIsRejected(string $keep): void
    {
        command("db:backup --keep {$keep}");

        $this->assertSame([], $this->backups(), "--keep {$keep} 은 거부돼야 한다.");
        $this->assertStringContainsString('--keep', $this->getStreamFilterBuffer());
    }

    /**
     * 음수(`--keep -3`)는 넣지 않는다 — CI4 CLI 파서가 `-` 로 시작하는 토큰을
     * 값이 아니라 또 다른 옵션으로 읽어, --keep 은 값 없이 기본값(10)이 된다.
     * 백업이 더 많이 남는 방향이라 사고가 아니고, 이 커맨드가 막을 수 있는 입력도 아니다.
     */
    public static function malformedKeepProvider(): array
    {
        return [
            '소수점'    => ['1.5'],
            '숫자+문자' => ['2abc'],
            '문자만'    => ['abc'],
        ];
    }

    /**
     * 파일명 시각은 앱 타임존이 아니라 UTC 여야 한다. DST 가 있는 지역이면
     * 시계가 되돌아가는 한 시간 동안 이름 정렬이 시간 정렬과 어긋나
     * 로테이션이 최신 백업을 지운다.
     */
    public function testFileNameStampIsUtc(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('America/New_York');  // UTC 와 4~5시간 차이

        try {
            $before = gmdate('YmdHis');
            command('db:backup');
            $after = gmdate('YmdHis');
        } finally {
            date_default_timezone_set($original);
        }

        $files = $this->backups();
        $this->assertCount(1, $files);

        $this->assertSame(
            1,
            preg_match('/^backup-(\d{8})-(\d{6})-\d{6}\.sqlite$/', basename($files[0]), $m),
            '파일명이 backup-<날짜>-<시각>-<마이크로초>.sqlite 형식이어야 한다.'
        );

        $stamp = $m[1] . $m[2];
        $this->assertGreaterThanOrEqual($before, $stamp, '파일명 시각이 UTC 보다 이르면 안 된다.');
        $this->assertLessThanOrEqual($after, $stamp, '파일명 시각이 UTC 보다 늦으면 안 된다(로컬 타임존을 썼다는 뜻).');
    }

    public function testSkipsWhenDriverIsNotSqlite(): void
    {
        // 테스트 DB 는 SQLite 라 MySQL 상황을 만들 수 없다.
        // 드라이버 판정 seam 만 바꿔 스킵 분기를 실제로 태운다.
        $command = new class (Services::logger(), Services::commands()) extends DbBackup {
            protected function driverName(): string
            {
                return 'MySQLi';
            }
        };

        $status = $command->run([]);

        $this->assertSame(EXIT_SUCCESS, $status, '비SQLite 는 실패가 아니라 스킵이다.');
        $this->assertSame([], $this->backups(), '스킵했으면 백업 파일이 없어야 한다.');
        $this->assertStringContainsString('MySQLi', $this->getStreamFilterBuffer());
    }
}
