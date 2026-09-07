<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * SQLite 를 WAL(Write-Ahead Logging) 모드로 바꾼다. (#175)
 *
 * 기본 rollback journal 에서는 쓰기가 진행되는 동안 다른 연결의 읽기까지 막힌다.
 * 운영에서 크롤러의 글 상세 읽기가 그 순간에 걸려 "database is locked" 로 500 이
 * 났다. WAL 에서는 읽기가 쓰기를 기다리지 않으므로 그 충돌 자체가 사라진다.
 *
 * journal_mode 는 DB 파일에 남는 속성이라 한 번만 바꾸면 된다. CI4 의 SQLite3
 * 설정에는 이걸 걸 훅이 없어서(busyTimeout·synchronous 뿐) 마이그레이션에 둔다.
 */
class EnableSqliteWalMode extends Migration
{
    public function up()
    {
        if ($this->driverName() !== 'SQLite3') {
            // 개발 DB 는 MySQL 이다. PRAGMA 를 그대로 던지면 마이그레이션이 깨진다.
            return;
        }

        $this->db->query('PRAGMA journal_mode = WAL');
    }

    /**
     * 되돌리지 않는다.
     *
     * WAL 은 파일 형식이 아니라 접근 방식의 선택이고, 되돌린다고 복구되는 데이터가
     * 없다. 오히려 운영에서 down() 이 돌면 #175 의 잠금 충돌이 그대로 되살아난다.
     * 정말 필요하면 손으로 `PRAGMA journal_mode = DELETE` 를 실행하면 된다.
     */
    public function down()
    {
        log_message('notice', 'EnableSqliteWalMode: down() 은 WAL 을 되돌리지 않는다. 필요하면 PRAGMA journal_mode = DELETE 를 손으로 실행할 것.');
    }

    /**
     * 연결의 드라이버 이름. 테스트가 비SQLite 경로를 확인할 수 있도록 seam 으로 뺀다.
     */
    protected function driverName(): string
    {
        return $this->db->DBDriver;
    }
}
