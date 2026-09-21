<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * 보관 기간을 넘긴 로그인 기록을 세고, --force 를 주면 지운다. (#184)
 *
 * Shield 는 로그인 시도마다 ip_address 와 user_agent 를 남긴다. 개인정보인데
 * 지우는 경로가 없어 무한정 쌓이고 있었다. 이건 디스크 문제가 아니라 준수
 * 문제다 — 개인정보보호법은 목적을 다한 개인정보의 파기를 요구한다.
 *
 * ⚠️ auth_remember_tokens 는 대상이 아니다. Shield 가 로그인할 때마다 이미
 * 만료분을 지운다(Session.php:777 → RememberModel::purgeOldRememberTokens()).
 *
 * ⚠️ Shield 는 사용자를 지워도 로그인 기록을 남긴다("Do NOT delete the user_id
 * or identifier when the user is deleted for security audits", create_auth_tables.php).
 * 의도적 설계이므로 cascade 를 추가하지 않는다. 대신 **기간**으로 판다 — 감사에
 * 필요한 창만 남기고 그 밖은 지우는 것이 두 요구를 함께 만족하는 방법이다.
 *
 * ## deploy.sh 에 넣는 이유 (DbPrune 주석과의 차이)
 *
 * DbPrune 은 "배포 파이프라인이 파괴적 삭제를 자동 실행해서는 안 된다" 고
 * 못 박는다. 그 판단은 유효하지만 대상이 다르다 — DbPrune 이 지우는 고아 행은
 * 만료 정책이 없는 실데이터이고, 여기서 지우는 것은 **보관 기간이 명시된
 * 로그성 개인정보**다. LogsPrune 이 같은 선을 이미 그어 뒀다.
 *
 * 그리고 결정적으로, 수동으로 두면 아무도 돌리지 않는다. 그러면 방침이 약속한
 * 파기를 실제로는 하지 않게 되고, 그게 바로 이 이슈를 만든 상태다(#183).
 * 배포 직전 db:backup 이 스냅샷을 남기므로 되돌릴 길도 있다.
 *
 * 사용 예:
 *   php spark auth:prune                       # 몇 건이 지워질지만 본다
 *   php spark auth:prune --force               # 실제로 지운다
 *   php spark auth:prune --force --keep-days 30
 */
class AuthPrune extends BaseCommand
{
    protected $group       = 'Auth';
    protected $name        = 'auth:prune';
    protected $description = '보관 기간을 넘긴 로그인 기록을 세고, --force 를 주면 지운다.';
    protected $usage       = 'auth:prune [--force] [--keep-days 90]';
    protected $options     = [
        '--force'     => '실제로 삭제한다. 없으면 대상 건수만 보여 준다.',
        '--keep-days' => '보관할 일수(기본은 Config\Blog::$loginLogKeepDays).',
    ];

    public function run(array $params)
    {
        $force    = array_key_exists('force', $params) || CLI::getOption('force');
        $keepDays = $this->keepDaysOption($params);

        if ($keepDays < 1) {
            CLI::error('--keep-days 는 1 이상의 정수여야 합니다.');

            return EXIT_ERROR;
        }

        $db     = Database::connect();
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$keepDays} days"));
        $counts = [];

        foreach ($this->tables() as $table) {
            $counts[$table] = $db->table($table)->where('date <', $cutoff)->countAllResults();
        }

        if (array_sum($counts) === 0) {
            CLI::write("정리할 로그인 기록이 없습니다 (보관 {$keepDays}일).", 'green');

            return EXIT_SUCCESS;
        }

        $this->report($counts, $keepDays);

        if (! $force) {
            CLI::write(
                '정리 대상 ' . array_sum($counts) . "건 (보관 {$keepDays}일) — 실제로 지우려면 --force",
                'yellow'
            );

            return EXIT_SUCCESS;
        }

        $deleted = 0;
        $failed  = 0;

        foreach ($this->tables() as $table) {
            if ($counts[$table] === 0) {
                continue;
            }

            // DBDebug=false 인 운영에서는 실패가 예외가 아니라 false 로 온다.
            // 그대로 넘기면 "지웠다" 고 보고하면서 아무것도 안 지운 상태가 된다.
            if ($db->table($table)->where('date <', $cutoff)->delete() === false) {
                $failed++;
                CLI::error("삭제 실패: {$table}");

                continue;
            }

            $deleted += $counts[$table];
        }

        // 보관 일수를 함께 찍는다 — 배포 로그만 보고도 어떤 기준으로 지웠는지 알 수 있어야 한다.
        CLI::write("로그인 기록 정리: {$deleted}건 삭제 (보관 {$keepDays}일)", 'green');

        // 커맨드는 실패를 정직하게 보고한다. "배포를 막을지" 는 deploy.sh 가 정한다(#135).
        return $failed > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }

    /**
     * 대상 테이블. 이름을 박지 않고 Shield 설정에서 읽는다 —
     * Config\Auth::$tables 를 바꾸면 이 커맨드가 엉뚱한 표를 보거나 아무것도
     * 못 찾는 상태로 조용히 넘어가기 때문이다.
     *
     * @return list<string>
     */
    private function tables(): array
    {
        $tables = config('Auth')->tables;

        return [$tables['logins'], $tables['token_logins']];
    }

    /** @param array<string, int> $counts */
    private function report(array $counts, int $keepDays): void
    {
        CLI::write("보관 {$keepDays}일을 넘긴 로그인 기록", 'yellow');

        foreach ($counts as $table => $count) {
            if ($count > 0) {
                CLI::write(sprintf('  %-22s %d건', $table, $count));
            }
        }
    }

    /**
     * --keep-days 값. 없으면 설정값을 쓴다.
     *
     * 기본값을 이 클래스에 박지 않는 것이 핵심이다 — 개인정보처리방침 화면이
     * 같은 값을 보여 주므로, 출처가 둘이면 조용히 갈라진다. 그 드리프트가
     * 바로 이 이슈(#184)를 만든 실패 유형이다.
     *
     * CI4 의 command() 파서는 `--keep-days 30` 형태만 값으로 읽는다
     * (`--keep-days=30` 은 옵션 이름 자체가 'keep-days=30' 이 된다).
     */
    private function keepDaysOption(array $params): int
    {
        $raw = $params['keep-days'] ?? CLI::getOption('keep-days');

        if ($raw === null || $raw === true || $raw === '') {
            return config('Blog')->loginLogKeepDays;
        }

        // (int) 캐스팅은 '3a' 를 3, '1.5' 를 1 로 조용히 받아 준다.
        // 숫자로만 이뤄진 문자열만 받고 나머지는 0 으로 넘겨 호출부 가드에 걸리게 한다.
        return ctype_digit((string) $raw) ? (int) $raw : 0;
    }
}
