<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * php spark db:prune — 이미 쌓인 고아 행 정리.
 *
 * 되돌릴 수 없는 DELETE 라 기본은 건수만 보고하고, --force 를 줘야 지운다.
 * "--force 없이는 아무것도 안 지운다"는 음성 단언이 이 커맨드의 핵심 계약이다.
 *
 * command() 는 system/Common.php 에서 ob_start()/ob_get_contents() 로 감싸지만,
 * CLI::write() 는 STDOUT 에 직접 fwrite() 하므로(PHP CLI SAPI 는 STDOUT 을 이미
 * 정의해 두어 출력 버퍼링을 타지 않는다) command() 의 반환값은 항상 빈 문자열이다.
 * 그래서 StreamFilterTrait 로 STDOUT 을 가로채 getStreamFilterBuffer() 로 확인한다.
 */
final class DbPruneCommandTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $namespace = null;
    protected $refresh   = true;

    /**
     * 고아 행을 심는다. 부모 없는 id 를 직접 넣는 방식이라
     * FK 가 켜진 테이블에는 쓰지 않는다(post_likes·comment_likes·comment_reports 는 SQLite FK 가 없다).
     */
    private function seedOrphans(): void
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        // 존재하지 않는 글 999 / 댓글 998 을 가리키는 행들
        $db->table('post_likes')->insert(['post_id' => 999, 'user_id' => 1, 'created_at' => $now]);
        $db->table('comment_likes')->insert(['comment_id' => 998, 'user_id' => 1, 'created_at' => $now]);
        $db->table('comment_reports')->insert([
            'comment_id'       => 998,
            'reporter_user_id' => 1,
            'reason'           => 'spam',
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }

    private function orphanCount(): int
    {
        $db = db_connect();

        return $db->table('post_likes')->where('post_id', 999)->countAllResults()
            + $db->table('comment_likes')->where('comment_id', 998)->countAllResults()
            + $db->table('comment_reports')->where('comment_id', 998)->countAllResults();
    }

    public function testDryRunReportsButDeletesNothing(): void
    {
        $this->seedOrphans();

        command('db:prune');

        $this->assertSame(3, $this->orphanCount(), '--force 없이 지워 버렸다');
        $this->assertStringContainsString('--force', $this->getStreamFilterBuffer(), '--force 안내가 없다');
    }

    public function testForceDeletesOrphans(): void
    {
        $this->seedOrphans();

        command('db:prune --force');

        $this->assertSame(0, $this->orphanCount(), '고아 행이 남았다');
    }

    /** 멀쩡한 행은 건드리지 않는다. 무차별 삭제를 잡는다. */
    public function testForceKeepsRowsWithLivingParents(): void
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $db->table('posts')->insert([
            'title' => '살아 있는 글', 'slug' => 'alive-' . uniqid(), 'body' => 'b',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $postId = (int) $db->insertID();

        $db->table('comments')->insert([
            'post_id' => $postId, 'user_id' => 1, 'body' => 'c',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $commentId = (int) $db->insertID();

        $db->table('post_likes')->insert(['post_id' => $postId, 'user_id' => 1, 'created_at' => $now]);
        $db->table('comment_likes')->insert(['comment_id' => $commentId, 'user_id' => 1, 'created_at' => $now]);

        command('db:prune --force');

        $this->assertSame(1, $db->table('post_likes')->where('post_id', $postId)->countAllResults(), '멀쩡한 post_likes 를 지웠다');
        $this->assertSame(1, $db->table('comment_likes')->where('comment_id', $commentId)->countAllResults(), '멀쩡한 comment_likes 를 지웠다');
        $this->assertSame(1, $db->table('comments')->where('id', $commentId)->countAllResults(), '멀쩡한 댓글을 지웠다');
    }

    /**
     * 고아 댓글을 지우면 그 댓글의 좋아요가 **새 고아**가 된다.
     * 한 번 훑고 끝내면 남으므로, 더 지울 것이 없을 때까지 반복해야 한다.
     */
    public function testForceRepeatsUntilNoOrphansRemain(): void
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        // 존재하지 않는 글 997 을 가리키는 고아 댓글.
        // comments.post_id 는 SQLite 에서도 FK 가 걸려 있고(다른 세 테이블과 달리 조건부가 아님),
        // tests 그룹은 foreignKeys=true 라 직접 삽입이 그대로는 막힌다. 삽입 순간만 잠깐 꺼서
        // "이미 쌓여 있던 고아 행"이라는 시나리오(운영 SQLite 는 FK 미강제)를 재현한다.
        $db->disableForeignKeyChecks();
        $db->table('comments')->insert([
            'post_id' => 997, 'user_id' => 1, 'body' => '고아 댓글',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $orphanCommentId = (int) $db->insertID();
        $db->enableForeignKeyChecks();

        // 그 댓글의 좋아요는 지금은 부모가 있으므로 고아가 아니다.
        $db->table('comment_likes')->insert([
            'comment_id' => $orphanCommentId, 'user_id' => 1, 'created_at' => $now,
        ]);

        command('db:prune --force');

        $this->assertSame(
            0,
            $db->table('comments')->where('id', $orphanCommentId)->countAllResults(),
            '고아 댓글이 남았다'
        );
        $this->assertSame(
            0,
            $db->table('comment_likes')->where('comment_id', $orphanCommentId)->countAllResults(),
            '고아 댓글을 지운 뒤 생긴 좋아요 고아가 남았다 — 반복 정리가 안 된다'
        );
    }

    /**
     * 답글 2단 체인으로 바깥(for) 루프가 실제로 필요함을 증명한다.
     *
     * C2 는 존재하지 않는 댓글(9999)을 부모로 둔 1단 고아 답글이고,
     * C3 는 C2 를 부모로 둔다 — 지금은 C2 가 있으니 C3 는 고아가 아니다.
     * orphanReplyIds() 는 SQL 스냅샷을 한 번만 찍으므로, 한 라운드 안에서는
     * C2 만 고아로 잡혀 지워지고 C3 는 그 다음 라운드에야 고아가 된다.
     * 루프가 1회로 고정되면 C3 가 남아, 이 테스트가 실패로 잡아낸다.
     */
    public function testForceCleansUpTwoStepOrphanReplyChain(): void
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $db->table('posts')->insert([
            'title' => '답글 체인 글', 'slug' => 'reply-chain-' . uniqid(), 'body' => 'b',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $postId = (int) $db->insertID();

        // C2: 존재하지 않는 댓글 9999 를 부모로 둔 1단 고아 답글.
        // parent_id 는 SQLite 테스트 그룹에 FK 가 없으므로 그대로 넣어도 된다.
        $db->table('comments')->insert([
            'post_id' => $postId, 'user_id' => 1, 'body' => 'C2',
            'parent_id' => 9999,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $c2Id = (int) $db->insertID();

        // C3: 지금은 살아 있는 C2 를 부모로 두므로 이 시점엔 고아가 아니다.
        $db->table('comments')->insert([
            'post_id' => $postId, 'user_id' => 1, 'body' => 'C3',
            'parent_id' => $c2Id,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $c3Id = (int) $db->insertID();

        command('db:prune --force');

        $this->assertSame(
            0,
            $db->table('comments')->where('id', $c2Id)->countAllResults(),
            '1단 고아 답글 C2 가 남았다'
        );
        $this->assertSame(
            0,
            $db->table('comments')->where('id', $c3Id)->countAllResults(),
            'C2 가 지워지며 새로 고아가 된 C3 가 남았다 — 바깥 루프가 한 번만 돌았을 가능성이 있다'
        );
    }

    /**
     * 반복 상한(MAX_ROUNDS)보다 깊은 고아 답글 체인을 만들어, 상한에 걸렸을 때
     * "성공"으로 조용히 끝나지 않고 남은 고아를 알리며 실패 종료 코드를 돌려주는지 확인한다.
     *
     * 각 세대는 앞 세대가 지워진 뒤에야 고아가 되므로 한 라운드에 한 세대씩만 지워진다.
     * 15세대 체인이면 MAX_ROUNDS(10회)로는 다 지우지 못하고 5세대가 남는다.
     */
    public function testForceStopsAtRoundLimitAndReportsRemainingOrphans(): void
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $db->table('posts')->insert([
            'title' => '깊은 답글 체인 글', 'slug' => 'deep-reply-chain-' . uniqid(), 'body' => 'b',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $postId = (int) $db->insertID();

        $generations = 15; // MAX_ROUNDS(10) 보다 깊게 만들어야 상한에 걸린다.
        $parentId    = 999999; // 존재하지 않는 조상 — 1세대는 처음부터 고아.

        for ($i = 0; $i < $generations; $i++) {
            $db->table('comments')->insert([
                'post_id' => $postId, 'user_id' => 1, 'body' => "세대 {$i}",
                'parent_id' => $parentId,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $parentId = (int) $db->insertID();
        }

        // command() 는 CLI::write() 가 STDOUT 에 직접 쓰기 때문에 반환값이 항상 빈 문자열이고
        // service('commands')->run() 의 종료 코드도 함께 버려진다. 종료 코드를 직접 확인하려면
        // Commands 서비스를 통해 커맨드를 실행해야 한다.
        $exitCode = service('commands')->run('db:prune', ['force' => null]);

        $this->assertNotSame(
            EXIT_SUCCESS,
            $exitCode,
            '반복 상한에 걸려 고아가 남았는데도 성공 종료 코드를 돌려주었다'
        );

        $this->assertStringContainsString(
            '상한',
            $this->getStreamFilterBuffer(),
            '반복 상한에 도달했다는 사실을 안내하지 않는다'
        );

        $this->assertGreaterThan(
            0,
            $db->table('comments')->where('post_id', $postId)->countAllResults(),
            '15세대 체인이 상한(10회) 안에 전부 지워졌다 — 테스트 전제가 깨졌다(체인 깊이나 MAX_ROUNDS 를 확인하라)'
        );
    }
}
