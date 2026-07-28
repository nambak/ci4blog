<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
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
}
