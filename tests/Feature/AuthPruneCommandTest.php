<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * php spark auth:prune — 보관 기간을 넘긴 로그인 기록 파기. (#184)
 *
 * Shield 는 로그인 시도마다 auth_logins 에 ip_address 와 user_agent 를 남긴다.
 * 개인정보인데 지우는 경로가 없어 무한정 쌓이고 있었다. 개인정보보호법은 목적을
 * 다한 개인정보의 파기를 요구하므로, 이건 디스크 문제가 아니라 준수 문제다.
 *
 * ⚠️ auth_remember_tokens 는 대상이 아니다 — Shield 가 로그인할 때마다 이미
 * 만료분을 지운다(Session.php:777 → RememberModel::purgeOldRememberTokens()).
 *
 * 되돌릴 수 없는 DELETE 라 기본은 건수만 보고하고 --force 를 줘야 지운다.
 * "--force 없이는 아무것도 안 지운다" 가 이 커맨드의 핵심 계약이다.
 *
 * command() 의 반환값이 아니라 StreamFilterTrait 로 STDOUT 을 가로채는 이유는
 * DbPruneCommandTest 머리말에 적혀 있다(CLI::write 는 출력 버퍼를 타지 않는다).
 *
 * @internal
 */
final class AuthPruneCommandTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use StreamFilterTrait;

    protected $namespace = null;
    protected $refresh   = true;

    private function keepDays(): int
    {
        return config('Blog')->loginLogKeepDays;
    }

    /** $daysAgo 일 전 로그인 기록 한 건. 두 로그 테이블 모두 컬럼이 같다. */
    private function seedLogin(string $table, int $daysAgo, string $identifier): void
    {
        db_connect()->table($table)->insert([
            'ip_address' => '203.0.113.7',
            'user_agent' => 'probe/1.0',
            'id_type'    => 'email',
            'identifier' => $identifier,
            'user_id'    => null,
            'date'       => date('Y-m-d H:i:s', strtotime("-{$daysAgo} days")),
            'success'    => 0,
        ]);
    }

    private function countIn(string $table): int
    {
        return db_connect()->table($table)->countAllResults();
    }

    /** 남아 있는 행의 identifier 목록 — "몇 건" 이 아니라 "어느 것" 이 남았는지 본다. */
    private function survivorsIn(string $table): array
    {
        $rows = db_connect()->table($table)->select('identifier')->orderBy('identifier')->get()->getResultArray();

        return array_column($rows, 'identifier');
    }

    // ---------------------------------------------------------------- 핵심 계약

    /** --force 없이는 세기만 한다. 이걸 어기면 배포가 조용히 데이터를 지운다. */
    public function testDryRunReportsButDeletesNothing(): void
    {
        $this->seedLogin('auth_logins', $this->keepDays() + 10, 'old@example.com');

        command('auth:prune');

        $this->assertSame(1, $this->countIn('auth_logins'), '--force 없이 지워 버렸다.');
        $this->assertStringContainsString('--force', $this->getStreamFilterBuffer(), '--force 안내가 없다.');
    }

    /**
     * 보관 기간을 넘긴 것만 지운다.
     *
     * 개수만 세면 "전부 지움" 도 통과한다. 어느 것이 남았는지를 본다.
     */
    public function testForceDeletesOnlyRowsPastRetention(): void
    {
        $keep = $this->keepDays();
        $this->seedLogin('auth_logins', $keep + 10, 'old@example.com');
        $this->seedLogin('auth_logins', $keep - 10, 'recent@example.com');

        command('auth:prune --force');

        $this->assertSame(['recent@example.com'], $this->survivorsIn('auth_logins'));
    }

    /** 토큰 로그인 기록도 같은 규칙으로 지운다 — API 호출마다 쌓이는 같은 모양의 표다. */
    public function testTokenLoginsArePrunedToo(): void
    {
        $keep = $this->keepDays();
        $this->seedLogin('auth_token_logins', $keep + 10, 'old-token@example.com');
        $this->seedLogin('auth_token_logins', $keep - 10, 'recent-token@example.com');

        command('auth:prune --force');

        $this->assertSame(['recent-token@example.com'], $this->survivorsIn('auth_token_logins'));
    }

    /** 경계 바로 안쪽은 남는다 — 하루 차이로 감사 기록이 사라지면 안 된다. */
    public function testRowJustInsideRetentionSurvives(): void
    {
        $this->seedLogin('auth_logins', $this->keepDays() - 1, 'inside@example.com');

        command('auth:prune --force');

        $this->assertSame(['inside@example.com'], $this->survivorsIn('auth_logins'));
    }

    /** 경계 바로 바깥은 지워진다. 위 테스트와 짝이라 둘이 함께 경계를 못 박는다. */
    public function testRowJustOutsideRetentionIsDeleted(): void
    {
        $this->seedLogin('auth_logins', $this->keepDays() + 1, 'outside@example.com');

        command('auth:prune --force');

        $this->assertSame([], $this->survivorsIn('auth_logins'));
    }

    // ---------------------------------------------------------------- 옵션

    public function testCustomKeepDaysNarrowsTheWindow(): void
    {
        // 기본 보관 기간 안쪽이지만 --keep-days 7 로는 바깥인 행.
        $this->seedLogin('auth_logins', 30, 'thirty@example.com');
        $this->seedLogin('auth_logins', 3, 'three@example.com');

        command('auth:prune --force --keep-days 7');

        $this->assertSame(['three@example.com'], $this->survivorsIn('auth_logins'));
    }

    public function testRejectsNonNumericKeepDays(): void
    {
        $this->seedLogin('auth_logins', 999, 'old@example.com');

        command('auth:prune --force --keep-days abc');

        // 종료 코드를 직접 볼 수 없으므로 두 가지로 확인한다 —
        // 오류 문구가 나왔고, 그리고 **아무것도 지우지 않았다.**
        $this->assertStringContainsString('--keep-days', $this->getStreamFilterBuffer());
        $this->assertSame(1, $this->countIn('auth_logins'), '잘못된 옵션인데 지워 버렸다.');
    }

    public function testReportsNothingToDoWhenAllRowsAreRecent(): void
    {
        $this->seedLogin('auth_logins', 1, 'recent@example.com');

        command('auth:prune');

        $this->assertStringContainsString('없습니다', $this->getStreamFilterBuffer());
        $this->assertSame(1, $this->countIn('auth_logins'));
    }

    // ------------------------------------------------- 문서와 동작이 갈라지지 않게
    //
    // 이 이슈(#184)가 생긴 원인 자체가 "방침에 적은 파기를 실제로 안 하고 있었다"
    // 였다. 같은 실패가 반대 방향(동작은 바뀌었는데 방침은 옛 숫자)으로 나지
    // 않게, 화면에 적힌 숫자와 커맨드가 실제로 쓰는 값을 맞대어 본다.

    /** 방침 화면이 설정값과 같은 일수를 말한다. 숫자를 뷰에 박으면 여기서 죽는다. */
    public function testPrivacyPolicyStatesTheConfiguredRetentionPeriod(): void
    {
        $html = html_entity_decode(
            $this->call('GET', 'privacy')->response()->getBody(),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $this->assertStringContainsString($this->keepDays() . '일', $html, '방침에 보관 기간이 없다.');
        $this->assertStringContainsString('자동으로 삭제', $html, '자동 파기를 명시하지 않았다.');
    }

    /**
     * 옵션 없이 부른 커맨드가 설정값을 쓴다.
     *
     * 커맨드에 기본값을 따로 박아 두면 방침과 조용히 갈라진다. 설정을 유일한
     * 출처로 두었는지 실제 동작으로 확인한다 — 설정값 바로 바깥의 행이 지워지고,
     * 바로 안쪽의 행이 남으면 그 값을 쓴 것이다.
     */
    public function testDefaultRetentionComesFromConfig(): void
    {
        $keep = $this->keepDays();
        $this->seedLogin('auth_logins', $keep + 1, 'outside@example.com');
        $this->seedLogin('auth_logins', $keep - 1, 'inside@example.com');

        command('auth:prune --force');

        $this->assertSame(['inside@example.com'], $this->survivorsIn('auth_logins'));
    }
}
