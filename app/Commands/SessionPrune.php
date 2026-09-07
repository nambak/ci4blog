<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Session as SessionConfig;

/**
 * 만료된 파일 세션(ci_session*)을 세고, --force 를 주면 지운다. (#179)
 *
 * 왜 필요한가: Debian/Ubuntu 의 PHP 는 session.gc_probability 가 0 이라 요청
 * 도중 GC 가 돌지 않고, 배포판이 넣어 주는 세션 정리 cron 은 php.ini 의 기본
 * save_path 만 본다. CI4 는 writable/session 을 쓰므로 아무도 지우지 않는다 —
 * 운영에 37,463개가 쌓였다.
 *
 * logs:prune 과 같은 성격이라 같은 모양으로 맞춘다: 기본은 스캔, 삭제는
 * --force 를 요구하고, 배포 파이프라인에서 실패해도 배포를 막지 않는다.
 *
 * 사용 예:
 *   php spark session:prune            # 몇 개가 지워질지만 본다
 *   php spark session:prune --force    # 실제로 지운다
 */
class SessionPrune extends BaseCommand
{
    protected $group       = 'Session';
    protected $name        = 'session:prune';
    protected $description = '만료된 파일 세션을 세고, --force 를 주면 지운다.';
    protected $usage       = 'session:prune [--force]';
    protected $options     = [
        '--force' => '실제로 삭제한다. 없으면 대상 개수만 보여 준다.',
    ];

    /**
     * 세션 파일 이름 패턴. 같은 디렉터리에 있는 index.html 처럼 세션이 아닌
     * 파일을 지우지 않도록 좁게 잡는다.
     */
    private const SESSION_GLOB = 'ci_session*';

    public function run(array $params)
    {
        $force = array_key_exists('force', $params) || CLI::getOption('force');
        $ttl   = $this->expiration();

        if ($ttl < 1) {
            // expiration 0 은 "브라우저를 닫을 때까지" 라는 뜻이라 파일 나이로
            // 만료를 판정할 수 없다. 그대로 계산하면 살아 있는 세션까지 지운다.
            CLI::error('session.expiration 이 0 이라 만료를 판정할 수 없습니다. 설정을 확인하세요.');

            return EXIT_ERROR;
        }

        $stale = $this->staleFiles($ttl);

        if ($stale === []) {
            CLI::write("정리할 세션이 없습니다 (만료 {$ttl}초).", 'green');

            return EXIT_SUCCESS;
        }

        if (! $force) {
            CLI::write(
                '정리 대상 ' . count($stale) . "개 (만료 {$ttl}초) — 실제로 지우려면 --force",
                'yellow'
            );

            return EXIT_SUCCESS;
        }

        $deleted = 0;
        $failed  = 0;

        foreach ($stale as $file) {
            if (@unlink($file)) {
                $deleted++;

                continue;
            }

            $failed++;
        }

        CLI::write("세션 {$deleted}개 삭제 (만료 {$ttl}초).", 'green');

        if ($failed > 0) {
            CLI::error("{$failed}개는 지우지 못했습니다 — 권한을 확인하세요.");

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }

    /** 마지막으로 쓴 지 $ttl 초가 지난 세션 파일들. */
    private function staleFiles(int $ttl): array
    {
        $cutoff = time() - $ttl;
        $stale  = [];

        foreach (glob($this->sessionDir() . DIRECTORY_SEPARATOR . self::SESSION_GLOB) ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                $stale[] = $file;
            }
        }

        return $stale;
    }

    /** 세션 저장 디렉터리. 테스트가 실제 세션을 건드리지 않도록 seam 으로 둔다. */
    protected function sessionDir(): string
    {
        return rtrim(config(SessionConfig::class)->savePath, '/\\');
    }

    /** 세션 수명(초). */
    protected function expiration(): int
    {
        return config(SessionConfig::class)->expiration;
    }
}
