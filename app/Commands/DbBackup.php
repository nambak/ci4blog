<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;
use DateTimeImmutable;

/**
 * SQLite DB 파일의 스냅샷을 writable/backups/ 에 남긴다.
 *
 * deploy.sh 가 migrate 직전에 부른다 — 마이그레이션이 데이터를 망가뜨렸을 때
 * 되돌릴 파일이 이 커맨드의 산출물이다. 실패하면 EXIT_ERROR 를 돌려주고,
 * deploy.sh 는 set -e 라 거기서 배포가 멈춘다(백업 없이 migrate 하지 않는다).
 *
 * cp 가 아니라 VACUUM INTO 를 쓰는 이유:
 *  - WAL 모드에서 쓰기가 진행 중이어도 일관된 스냅샷이 된다.
 *  - 결과가 -wal/-shm 동반 파일 없는 단일 파일이라 복구가 단순하다.
 *  - sqlite3 CLI 설치가 필요 없다(PHP sqlite3 확장만 쓴다).
 *
 * 사용 예:
 *   php spark db:backup
 */
class DbBackup extends BaseCommand
{
    protected $group       = 'Database';
    protected $name        = 'db:backup';
    protected $description = 'SQLite DB 스냅샷을 writable/backups/ 에 만든다.';
    protected $usage       = 'db:backup [--keep 10]';
    protected $options     = [
        '--keep' => '보관할 백업 개수(기본 10). 초과분은 오래된 것부터 지운다.',
    ];

    /** 기본 보관 개수. 디스크 상한이 예측 가능하도록 개수 기준으로 돌린다. */
    public const DEFAULT_KEEP = 10;

    public function run(array $params)
    {
        $keep = $this->keepOption($params);

        if ($keep < 1) {
            CLI::error('--keep 은 1 이상의 정수여야 합니다.');

            return EXIT_ERROR;
        }

        $dir = $this->backupDir();

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            CLI::error("백업 디렉터리를 만들 수 없습니다: {$dir}");

            return EXIT_ERROR;
        }

        $target = $this->uniquePath($dir);
        $db     = Database::connect();

        try {
            $result = $db->query('VACUUM INTO ' . $db->escape($target));
        } catch (DatabaseException $e) {
            CLI::error('백업 실패: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        // DBDebug=false(운영)면 실패가 예외가 아니라 false 로 온다.
        // 반환값과 실제 파일을 모두 확인한다.
        clearstatcache(true, $target);

        if ($result === false || ! is_file($target) || filesize($target) === 0) {
            CLI::error('백업 실패: 스냅샷 파일이 만들어지지 않았습니다 — ' . $target);

            return EXIT_ERROR;
        }

        CLI::write(
            '백업 완료: ' . basename($target) . ' (' . $this->humanSize((int) filesize($target)) . ')',
            'green'
        );

        $this->rotate($dir, $keep);

        return EXIT_SUCCESS;
    }

    /**
     * --keep 값. CI4 의 command() 파서는 `--keep 2` 형태만 값으로 읽는다
     * (`--keep=2` 는 옵션 이름 자체가 'keep=2' 가 된다).
     */
    private function keepOption(array $params): int
    {
        $raw = $params['keep'] ?? CLI::getOption('keep');

        if ($raw === null || $raw === true || $raw === '') {
            return self::DEFAULT_KEEP;
        }

        // 숫자가 아니면 0 이 되어 호출부의 가드에 걸린다.
        return (int) $raw;
    }

    /** 최신 $keep 개만 남기고 지운다. 파일명이 타임스탬프라 이름 정렬 = 시간 정렬이다. */
    private function rotate(string $dir, int $keep): void
    {
        $files = glob($dir . DIRECTORY_SEPARATOR . 'backup-*.sqlite') ?: [];
        rsort($files, SORT_STRING);

        foreach (array_slice($files, $keep) as $stale) {
            if (@unlink($stale)) {
                CLI::write('오래된 백업 삭제: ' . basename($stale));
            }
        }
    }

    /** 백업 디렉터리. 테스트가 위치를 알아야 하므로 한 곳에서만 만든다. */
    protected function backupDir(): string
    {
        return WRITEPATH . 'backups';
    }

    /**
     * 아직 없는 백업 파일 경로.
     *
     * 이름에 마이크로초까지 넣는다 — 이름 정렬이 곧 시간 정렬이어야
     * 로테이션이 "오래된 것부터" 지울 수 있고, 같은 초에 두 번 실행돼도
     * 이름이 겹치지 않는다(VACUUM INTO 는 대상 파일이 있으면 실패한다).
     */
    private function uniquePath(string $dir): string
    {
        $base = $dir . DIRECTORY_SEPARATOR . 'backup-' . (new DateTimeImmutable())->format('Ymd-His-u');
        $path = $base . '.sqlite';
        $seq  = 1;

        while (file_exists($path)) {
            $path = $base . '-' . $seq++ . '.sqlite';
        }

        return $path;
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }
}
