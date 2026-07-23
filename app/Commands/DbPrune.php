<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * 부모가 사라진 고아 행을 세고, --force 를 주면 지운다.
 *
 * 운영 DB 인 SQLite 는 FK 를 강제하지 않아, 앱에 정리 코드가 들어오기 전에
 * 지운 글·댓글의 자식 행이 남아 있다. 이 커맨드가 그것을 걷어낸다.
 *
 * 되돌릴 수 없는 DELETE 라 **기본은 건수만 보고한다.** deploy.sh 에 넣지 않는다 —
 * 배포 파이프라인이 파괴적 삭제를 자동 실행해서는 안 된다.
 *
 * 사용 예:
 *   php spark db:prune            # 몇 건인지만 본다
 *   php spark db:prune --force    # 실제로 지운다
 */
class DbPrune extends BaseCommand
{
    protected $group       = 'Database';
    protected $name        = 'db:prune';
    protected $description = '부모가 사라진 고아 행을 세고, --force 를 주면 지운다.';
    protected $usage       = 'db:prune [--force]';
    protected $options     = [
        '--force' => '실제로 삭제한다. 없으면 건수만 보여 준다.',
    ];

    /** 반복 상한. 정상 데이터에서는 2회면 끝난다 — 무한 루프 방지용 안전판이다. */
    private const MAX_ROUNDS = 10;

    public function run(array $params)
    {
        $force = array_key_exists('force', $params) || CLI::getOption('force');
        $db    = Database::connect();

        $counts = $this->scan($db);

        if (array_sum($counts) === 0) {
            CLI::write('고아 행이 없습니다.', 'green');

            return EXIT_SUCCESS;
        }

        $this->report($counts);

        if (! $force) {
            CLI::newLine();
            CLI::write('지우려면 --force 를 붙여 다시 실행하세요: php spark db:prune --force', 'yellow');

            return EXIT_SUCCESS;
        }

        $total = 0;

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $counts = $this->scan($db);

            if (array_sum($counts) === 0) {
                break;
            }

            // 댓글을 먼저 지우면 그 좋아요·신고가 새 고아가 되므로 다음 회차가 걷어 간다.
            $total += $this->purge($db);
        }

        // 반복 상한(MAX_ROUNDS)을 다 돌고도 고아가 남아 있을 수 있다 — 그대로 "성공"을
        // 출력하면 운영자가 정리가 끝난 줄 알고 넘어간다. 한 번 더 세어 확인한다.
        $remaining = $this->scan($db);

        if (array_sum($remaining) > 0) {
            CLI::newLine();
            CLI::write(
                "{$total}건을 삭제했지만, 반복 상한(" . self::MAX_ROUNDS . '회)에 도달해 고아 행이 여전히 남아 있습니다.',
                'red'
            );
            $this->report($remaining);
            CLI::newLine();
            CLI::write('db:prune --force 를 다시 실행해 남은 행을 마저 지우세요.', 'yellow');

            return EXIT_ERROR;
        }

        CLI::newLine();
        CLI::write("{$total}건을 삭제했습니다.", 'green');

        return EXIT_SUCCESS;
    }

    /**
     * 유형별 고아 건수.
     *
     * @return array<string, int>
     */
    private function scan(BaseConnection $db): array
    {
        return [
            '부모 글이 없는 댓글'        => $this->countOrphans($db, 'comments', 'post_id', 'posts'),
            '부모 댓글이 없는 답글'      => $this->countOrphanReplies($db),
            '부모 글이 없는 좋아요'      => $this->countOrphans($db, 'post_likes', 'post_id', 'posts'),
            '부모 댓글이 없는 좋아요'    => $this->countOrphans($db, 'comment_likes', 'comment_id', 'comments'),
            '부모 댓글이 없는 신고'      => $this->countOrphans($db, 'comment_reports', 'comment_id', 'comments'),
        ];
    }

    /** 지운 총 건수를 돌려준다. */
    private function purge(BaseConnection $db): int
    {
        $deleted = 0;

        // 댓글을 먼저 지운다 — 그래야 딸린 좋아요·신고가 다음 회차에 고아로 잡힌다.
        $deleted += $this->deleteOrphans($db, 'comments', 'post_id', 'posts');
        $deleted += $this->deleteOrphanReplies($db);
        $deleted += $this->deleteOrphans($db, 'post_likes', 'post_id', 'posts');
        $deleted += $this->deleteOrphans($db, 'comment_likes', 'comment_id', 'comments');
        $deleted += $this->deleteOrphans($db, 'comment_reports', 'comment_id', 'comments');

        return $deleted;
    }

    /** @param array<string, int> $counts */
    private function report(array $counts): void
    {
        CLI::write('고아 행', 'yellow');

        foreach ($counts as $label => $count) {
            if ($count > 0) {
                CLI::write(sprintf('  %-24s %d건', $label, $count));
            }
        }
    }

    /** 자식 테이블에서 부모가 사라진 행의 id 목록. */
    private function orphanIds(BaseConnection $db, string $child, string $fk, string $parent): array
    {
        $c = $db->prefixTable($child);
        $p = $db->prefixTable($parent);

        $rows = $db->query(
            "SELECT c.id AS id FROM {$c} c LEFT JOIN {$p} p ON p.id = c.{$fk} WHERE p.id IS NULL"
        )->getResultArray();

        return array_map('intval', array_column($rows, 'id'));
    }

    private function countOrphans(BaseConnection $db, string $child, string $fk, string $parent): int
    {
        return count($this->orphanIds($db, $child, $fk, $parent));
    }

    private function deleteOrphans(BaseConnection $db, string $child, string $fk, string $parent): int
    {
        $ids = $this->orphanIds($db, $child, $fk, $parent);

        if ($ids === []) {
            return 0;
        }

        $db->table($child)->whereIn('id', $ids)->delete();

        return count($ids);
    }

    /**
     * parent_id 가 가리키는 댓글이 사라진 답글.
     *
     * 같은 테이블을 참조하므로 파생 테이블로 감싼다 — MySQL 은 DELETE 대상 테이블을
     * 서브쿼리에서 직접 읽는 것을 막는다(에러 1093). 여기서는 id 를 PHP 로 먼저
     * 모아 두므로 그 제약을 아예 피한다.
     */
    private function orphanReplyIds(BaseConnection $db): array
    {
        $t = $db->prefixTable('comments');

        $rows = $db->query(
            "SELECT c.id AS id FROM {$t} c LEFT JOIN {$t} p ON p.id = c.parent_id"
            . ' WHERE c.parent_id IS NOT NULL AND p.id IS NULL'
        )->getResultArray();

        return array_map('intval', array_column($rows, 'id'));
    }

    private function countOrphanReplies(BaseConnection $db): int
    {
        return count($this->orphanReplyIds($db));
    }

    private function deleteOrphanReplies(BaseConnection $db): int
    {
        $ids = $this->orphanReplyIds($db);

        if ($ids === []) {
            return 0;
        }

        $db->table('comments')->whereIn('id', $ids)->delete();

        return count($ids);
    }
}
