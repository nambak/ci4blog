<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * 배포 스크립트에서 백업이 마이그레이션보다 앞서야 한다.
 *
 * 이 순서가 이 기능의 존재 이유다 — 나중에 스크립트를 손보다가 조용히
 * 뒤집히거나 빠지는 것을 막는 회귀 방지망이다.
 *
 * @internal
 */
final class DeployScriptOrderTest extends CIUnitTestCase
{
    private string $script;

    protected function setUp(): void
    {
        parent::setUp();

        $path = ROOTPATH . 'scripts/deploy.sh';
        $this->assertFileExists($path);
        $this->script = (string) file_get_contents($path);
    }

    public function testBackupRunsBeforeMigrate(): void
    {
        $backup  = strpos($this->script, 'spark db:backup');
        $migrate = strpos($this->script, 'spark migrate');

        $this->assertNotFalse($backup, 'deploy.sh 가 db:backup 을 실행해야 한다.');
        $this->assertNotFalse($migrate, 'deploy.sh 가 migrate 를 실행해야 한다.');
        $this->assertLessThan($migrate, $backup, '백업은 마이그레이션보다 앞서야 한다.');
    }

    public function testBackupFailureStopsDeploy(): void
    {
        // set -e 가 있어야 백업 실패가 배포 중단으로 이어진다.
        $this->assertStringContainsString('set -euo pipefail', $this->script);

        // `|| true` 로 실패를 삼키면 백업 없이 migrate 로 넘어간다.
        $this->assertDoesNotMatchRegularExpression(
            '/spark db:backup.*\|\|/',
            $this->script,
            'db:backup 실패를 삼키면 안 된다.'
        );
    }

    public function testLogPruneRunsInDeploy(): void
    {
        $this->assertStringContainsString(
            'spark logs:prune --force',
            $this->script,
            'deploy.sh 가 로그 정리를 실행해야 한다.'
        );
    }

    /**
     * 로그 정리는 백업과 반대다 — 실패해도 배포를 막지 않는다.
     *
     * 스크립트가 set -euo pipefail 이므로 `||` 로 받아 주지 않으면
     * 로그 정리 실패가 곧 배포 실패가 된다.
     */
    public function testLogPruneFailureDoesNotStopDeploy(): void
    {
        $this->assertMatchesRegularExpression(
            '/spark logs:prune --force[^\n]*(\\\\\n[^\n]*)?\|\|/',
            $this->script,
            '로그 정리 실패는 배포를 멈추지 않아야 한다.'
        );
    }

    /**
     * 로그 정리는 캐시 정리 뒤·권한 보정 앞에 있어야 한다.
     *
     * 특히 `composer install` 보다 앞서면 안 된다 — 새로 추가된 커맨드 클래스가
     * 이전 배포의 최적화된 classmap 에 없어 "command not found" 가 되고,
     * 그 실패는 `|| echo` 가 삼켜서 조용한 무동작이 된다. 문자열 존재만 보는
     * 위 두 테스트로는 이 사고를 잡지 못한다.
     */
    public function testLogPruneRunsAfterCacheClearAndBeforePermissionFix(): void
    {
        $composer   = strpos($this->script, 'composer install');
        $cacheClear = strpos($this->script, 'spark cache:clear');
        $logsPrune  = strpos($this->script, 'spark logs:prune');
        $chown      = strpos($this->script, 'chown -R www-data:www-data writable/');

        $this->assertNotFalse($composer, 'deploy.sh 가 composer install 을 실행해야 한다.');
        $this->assertNotFalse($cacheClear, 'deploy.sh 가 cache:clear 를 실행해야 한다.');
        $this->assertNotFalse($logsPrune, 'deploy.sh 가 logs:prune 을 실행해야 한다.');
        $this->assertNotFalse($chown, 'deploy.sh 가 writable 권한을 보정해야 한다.');

        $this->assertLessThan($logsPrune, $composer, '로그 정리는 의존성 설치보다 뒤에 있어야 한다.');
        $this->assertLessThan($logsPrune, $cacheClear, '캐시 정리가 로그 정리보다 앞서야 한다.');
        $this->assertLessThan($chown, $logsPrune, '로그 정리는 권한 보정보다 앞서야 한다.');
    }
}
