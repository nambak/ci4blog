<?php

use App\Database\Migrations\EnableSqliteWalMode;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database as DatabaseConfig;

/**
 * SQLite 는 쓰기 중에 다른 연결의 접근을 잠근다. busy timeout 이 없으면 그 순간
 * 들어온 요청이 곧바로 "database is locked" 로 죽는다(#175 — 크롤러의 글 상세
 * 읽기 2건이 그렇게 500 이 됐다).
 *
 * 운영 .env 는 DBDriver 만 SQLite3 로 바꾸므로 busyTimeout 같은 나머지 키는
 * Config\Database::$default 에서 온다. MySQLi\Connection 에는 busyTimeout
 * 속성이 없어(BaseConnection 은 property_exists 인 키만 반영) 개발 MySQL 은
 * 영향을 받지 않는다.
 *
 * @internal
 */
final class SqliteConcurrencyTest extends CIUnitTestCase
{
    private string $file = '';

    /**
     * 마이그레이션 파일은 PSR-4 이름 규칙을 따르지 않는다(날짜 접두사). 평소에는
     * MigrationRunner 가 파일명 규칙으로 require 하지만, 이 테스트는 러너를 거치지
     * 않고 클래스를 직접 쓰므로 여기서 읽어 온다.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        require_once APPPATH . 'Database/Migrations/2026-09-07-000000_EnableSqliteWalMode.php';
    }

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->file . '-wal', $this->file . '-shm', $this->file . '-journal'] as $path) {
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function testDefaultGroupConfiguresBusyTimeout(): void
    {
        $busyTimeout = (new DatabaseConfig())->default['busyTimeout'] ?? null;

        // 문자열 '1000' 이어도 실제로는 동작한다 — BaseConnection 이 typed property
        // (?int)에 맞춰 캐스팅하기 때문이다. 그래도 int 로 못 박는 이유는 설정의
        // 의도를 분명히 하기 위해서다. 실제 대기가 생기는지는 아래 테스트가 잰다.
        $this->assertIsInt($busyTimeout);
        $this->assertGreaterThan(0, $busyTimeout, '0 이면 잠금과 겹친 요청이 곧바로 죽는다.');
    }

    /**
     * 설정값이 실제로 대기를 만드는지 대조로 확인한다. 값이 Config 에 적혀 있어도
     * 연결에 닿지 않으면 아무 소용이 없다.
     */
    public function testBusyTimeoutMakesTheConnectionWaitInsteadOfFailingAtOnce(): void
    {
        $blocker = new SQLite3($this->newDbFile());
        $blocker->exec('BEGIN IMMEDIATE'); // 쓰기 잠금을 잡고 놓지 않는다

        $configured = (new DatabaseConfig())->default['busyTimeout'];

        $withTimeout    = $this->msUntilWriteFails($this->sqliteConfig());
        $withoutTimeout = $this->msUntilWriteFails(array_diff_key($this->sqliteConfig(), ['busyTimeout' => null]));

        $blocker->close();

        $this->assertGreaterThanOrEqual(
            $configured * 0.8,
            $withTimeout,
            'busyTimeout 이 걸린 연결은 그 시간만큼 잠금이 풀리길 기다려야 한다.',
        );
        $this->assertLessThan(
            $configured * 0.5,
            $withoutTimeout,
            '대조군 — 설정이 없으면 곧바로 실패한다. 이게 느리면 위 단언은 대기를 잰 게 아니다.',
        );
    }

    /** 잠긴 DB 에 쓰기를 시도해 실패까지 걸린 시간(ms). */
    private function msUntilWriteFails(array $config): float
    {
        $db    = DatabaseConfig::connect($config, false);
        $start = microtime(true);

        try {
            $db->query('CREATE TABLE probe_' . bin2hex(random_bytes(4)) . ' (id INTEGER)');
        } catch (Throwable) {
            // 잠금으로 실패하는 것이 정상이다. 재는 것은 "얼마나 버텼는가" 다.
        }

        $elapsed = (microtime(true) - $start) * 1000;
        $db->close();

        return $elapsed;
    }

    /** 운영과 같은 모양의 설정 — $default 에서 드라이버와 파일만 바꾼다. */
    private function sqliteConfig(): array
    {
        $config             = (new DatabaseConfig())->default;
        $config['DBDriver'] = 'SQLite3';
        $config['database'] = $this->file;
        $config['DBDebug']  = true;

        return $config;
    }

    private function newDbFile(): string
    {
        $this->file = tempnam(sys_get_temp_dir(), 'ci4blog-lock-') . '.sqlite';
        (new SQLite3($this->file))->close();

        return $this->file;
    }

    /**
     * WAL 이면 읽기가 쓰기를 기다리지 않는다 — busy timeout 이 보조라면 이쪽이
     * 본 방어다. CI4 의 SQLite3 설정에는 journal_mode 훅이 없어 마이그레이션으로
     * 넣는다(WAL 은 DB 파일에 남는 속성이라 한 번이면 된다).
     */
    public function testMigrationSwitchesFileDatabaseToWal(): void
    {
        $this->newDbFile();
        $forge = DatabaseConfig::forge($this->sqliteConfig());

        (new EnableSqliteWalMode($forge))->up();

        $this->assertSame('wal', $this->journalModeOf($forge->getConnection()));
    }

    /**
     * 개발 DB 는 MySQL 이다. PRAGMA 를 그대로 던지면 마이그레이션이 깨진다.
     */
    public function testMigrationLeavesNonSqliteAlone(): void
    {
        $this->newDbFile();
        $forge  = DatabaseConfig::forge($this->sqliteConfig());
        $before = $this->journalModeOf($forge->getConnection());

        // 대조군 — 애초에 wal 이었다면 아래 단언은 아무것도 증명하지 못한다.
        $this->assertNotSame('wal', $before);

        $migration = new class ($forge) extends EnableSqliteWalMode {
            protected function driverName(): string
            {
                return 'MySQLi';
            }
        };
        $migration->up();

        $this->assertSame($before, $this->journalModeOf($forge->getConnection()));
    }

    private function journalModeOf(object $db): string
    {
        return strtolower((string) $db->query('PRAGMA journal_mode')->getRowArray()['journal_mode']);
    }
}
