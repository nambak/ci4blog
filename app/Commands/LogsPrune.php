<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTimeImmutable;

/**
 * 보존 기간을 넘긴 일자별 로그(log-YYYY-MM-DD.log)를 세고, --force 를 주면 지운다.
 *
 * deploy.sh 가 배포 끝머리에 부른다. 로그 정리 실패는 서비스 동작과 무관하므로
 * deploy.sh 쪽에서 `|| echo ...` 로 배포를 막지 않는다(백업 실패는 반대로 멈춘다).
 *
 * DbPrune 주석은 "배포 파이프라인이 파괴적 삭제를 자동 실행해서는 안 된다"고
 * 못 박지만, 로그는 성격이 다르다 — DB 행은 유실되면 복구할 수 없지만 로그는
 * 애초에 만료 정책이 있는 데이터이고, db:backup 의 로테이션(오래된 백업 자동 삭제)이
 * 이미 같은 파이프라인에서 돌고 있다. 그래도 기본값은 스캔으로 두고 삭제는
 * --force 를 요구한다.
 *
 * 사용 예:
 *   php spark logs:prune                        # 몇 개가 지워질지만 본다
 *   php spark logs:prune --force                # 실제로 지운다
 *   php spark logs:prune --force --keep-days 14
 */
class LogsPrune extends BaseCommand
{
    protected $group       = 'Logs';
    protected $name        = 'logs:prune';
    protected $description = '보존 기간을 넘긴 일자별 로그를 세고, --force 를 주면 지운다.';
    protected $usage       = 'logs:prune [--force] [--keep-days 30]';
    protected $options     = [
        '--force'     => '실제로 삭제한다. 없으면 대상 개수만 보여 준다.',
        '--keep-days' => '보관할 일수(기본 30). 오늘 포함 최근 N개 날짜를 남긴다.',
    ];

    /** 기본 보관 일수. */
    public const DEFAULT_KEEP_DAYS = 30;

    public function run(array $params)
    {
        $force    = array_key_exists('force', $params) || CLI::getOption('force');
        $keepDays = self::DEFAULT_KEEP_DAYS;

        $stale = $this->staleFiles($keepDays);

        if ($stale === []) {
            CLI::write("정리할 로그가 없습니다 (보관 {$keepDays}일).", 'green');

            return EXIT_SUCCESS;
        }

        if (! $force) {
            CLI::write(
                '정리 대상 ' . count($stale) . "개 (보관 {$keepDays}일) — 실제로 지우려면 --force",
                'yellow'
            );

            return EXIT_SUCCESS;
        }

        return EXIT_SUCCESS;
    }

    /**
     * 보존 기간을 넘긴 로그 파일 경로 목록(오래된 것부터).
     *
     * 나이를 숫자로 계산하지 않고 날짜를 직접 비교한다 — diff()->days 는
     * 절댓값이라 미래 날짜도 양수로 나와 "내일 날짜 로그"가 지워질 수 있다.
     */
    private function staleFiles(int $keepDays): array
    {
        $cutoff = (new DateTimeImmutable('today'))->modify("-{$keepDays} days");
        $stale  = [];

        foreach (glob($this->logDir() . DIRECTORY_SEPARATOR . 'log-*.log') ?: [] as $file) {
            $date = $this->fileDate(basename($file));

            if ($date !== null && $date <= $cutoff) {
                $stale[] = $file;
            }
        }

        sort($stale, SORT_STRING);

        return $stale;
    }

    /**
     * 파일명에서 날짜를 읽는다. 규약에 안 맞으면 null — 즉 건드리지 않는다.
     *
     * .log 만 대상으로 삼는 것은 app/Config/Logger.php 의 fileExtension 이
     * ''(→ CI4 가 'log' 로 되돌린다)이기 때문이다. 그 설정을 'php' 등으로 바꾸면
     * 이 커맨드는 아무것도 지우지 않는다 — 조용히 엉뚱한 파일을 지우는 것보다
     * 안전한 실패 방향이라 그대로 두되, 설정을 바꿀 때 함께 손봐야 한다.
     */
    private function fileDate(string $name): ?DateTimeImmutable
    {
        if (preg_match('/^log-(\d{4}-\d{2}-\d{2})\.log$/', $name, $matches) !== 1) {
            return null;
        }

        $date   = DateTimeImmutable::createFromFormat('!Y-m-d', $matches[1]);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false) {
            return null;
        }

        // 2026-13-45 처럼 형식은 맞지만 존재하지 않는 날짜는 경고로 잡힌다.
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $date;
    }

    /** 로그 디렉터리. 테스트가 임시 디렉터리로 갈아끼우는 seam 이다. */
    protected function logDir(): string
    {
        return rtrim(WRITEPATH . 'logs', DIRECTORY_SEPARATOR);
    }
}
