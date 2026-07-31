<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * 배포 후 smoke test 스크립트. (#112)
 *
 * 배포가 SSH 성공만으로 초록이 되던 구멍을 막는 스크립트다. 셸 스크립트는
 * 문자열만 봐서는 동작을 보장할 수 없어, 이 테스트는 실제로 실행까지 한다.
 *
 * @internal
 */
final class SmokeScriptTest extends CIUnitTestCase
{
    private string $path;
    private string $script;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = ROOTPATH . 'scripts/smoke.sh';
        $this->assertFileExists($this->path);
        $this->assertTrue(
            is_executable($this->path),
            'smoke.sh 에 실행 권한이 있어야 한다(git 에 100755 로 커밋).'
        );
        $this->script = (string) file_get_contents($this->path);
    }

    /**
     * 스크립트를 실제로 실행한다.
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{output: string, exit: int}
     */
    private function runSmoke(array $args = [], array $env = []): array
    {
        $cmd = '';

        foreach ($env as $name => $value) {
            $cmd .= $name . '=' . escapeshellarg((string) $value) . ' ';
        }

        $cmd .= escapeshellarg($this->path);

        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        // stderr 까지 봐야 usage 를 확인할 수 있다.
        exec($cmd . ' 2>&1', $lines, $exitCode);

        return ['output' => implode("\n", $lines), 'exit' => $exitCode];
    }

    public function testStartsWithStrictMode(): void
    {
        $this->assertStringContainsString('set -euo pipefail', $this->script);
    }

    /**
     * base URL 은 필수다 — 기본값을 두면 잘못된 환경을 조용히 검사한다.
     */
    public function testRequiresBaseUrlArgument(): void
    {
        $result = $this->runSmoke();

        $this->assertSame(
            2,
            $result['exit'],
            "인자 없이 실행하면 종료코드 2여야 한다. 출력: {$result['output']}"
        );
        $this->assertStringContainsString('usage:', $result['output']);
    }
}
