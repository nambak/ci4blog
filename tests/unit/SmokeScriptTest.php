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

    /** 대표 5경로를 모두 검사해야 한다. */
    public function testChecksAllFivePaths(): void
    {
        foreach (['/health', '/posts', '/sitemap.xml', '/feed'] as $path) {
            $this->assertStringContainsString(
                $path,
                $this->script,
                "smoke.sh 가 {$path} 를 검사해야 한다."
            );
        }

        // 홈은 경로가 '/' 하나라 문자열 검색이 무의미하다. 호출부 형태로 본다.
        $this->assertMatchesRegularExpression(
            '/^check\s+\/\s+/m',
            $this->script,
            'smoke.sh 가 홈(/)을 검사해야 한다.'
        );
    }

    /**
     * /health 는 상태코드만 보지 않는다.
     *
     * 지금 구현은 DB 가 살아 있어야만 200 을 주지만, 그것은 컨트롤러가 지키는
     * 계약일 뿐이다. 200 + db:down 을 반환하도록 바뀌면 상태코드만 보는 검사는
     * 조용히 통과한다.
     */
    public function testHealthCheckAssertsBody(): void
    {
        $this->assertStringContainsString('"status":"ok"', $this->script);
        $this->assertStringContainsString('"db":"ok"', $this->script);
    }

    /** 재시도 설정은 환경변수로 조절할 수 있어야 한다(테스트가 쓰는 seam). */
    public function testRetrySettingsAreConfigurable(): void
    {
        $this->assertStringContainsString('SMOKE_RETRIES:-3', $this->script);
        $this->assertStringContainsString('SMOKE_DELAY:-3', $this->script);
        $this->assertStringContainsString('SMOKE_TIMEOUT:-10', $this->script);
    }

    /**
     * 사이트에 닿지 못하면 실패로 끝나야 한다.
     *
     * 닫힌 포트는 즉시 연결 거부되므로(실측 8ms) 네트워크 의존이 없다.
     * 재시도·타임아웃을 최소로 낮춰 테스트가 몇 초 안에 끝나게 한다.
     */
    public function testFailsWhenSiteIsUnreachable(): void
    {
        $result = $this->runSmoke(
            ['http://127.0.0.1:1'],
            ['SMOKE_RETRIES' => '1', 'SMOKE_DELAY' => '0', 'SMOKE_TIMEOUT' => '1']
        );

        $this->assertSame(
            1,
            $result['exit'],
            "닿지 못하면 종료코드 1이어야 한다. 출력: {$result['output']}"
        );
        $this->assertStringContainsString('smoke test 실패', $result['output']);
    }

    /**
     * 첫 실패에서 멈추지 않는다 — 5경로를 모두 검사하고 끝에 요약한다.
     *
     * /health 만 죽었는지 전부 죽었는지가 원인 판단(DB 문제 vs 앱 전체 다운)에
     * 직접 쓰인다. 첫 실패에서 exit 해 버리면 실패 표시가 하나만 남아 이 구분이
     * 사라진다 — 그 뮤테이션을 이 테스트가 잡는다.
     */
    public function testKeepsCheckingAfterFirstFailure(): void
    {
        $result = $this->runSmoke(
            ['http://127.0.0.1:1'],
            ['SMOKE_RETRIES' => '1', 'SMOKE_DELAY' => '0', 'SMOKE_TIMEOUT' => '1']
        );

        $this->assertSame(
            5,
            substr_count($result['output'], '✗'),
            "5경로 모두 실패 표시가 있어야 한다. 출력: {$result['output']}"
        );
        $this->assertStringContainsString('5/5', $result['output']);
    }
}
