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
}
