<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * 배포 워크플로가 배포 결과를 실제로 검증해야 한다. (#112)
 *
 * SSH 성공이 곧 배포 성공으로 기록되던 구멍을 막는 배선이다. 배선이 조용히
 * 빠지거나 뒤집히는 것을 막는 회귀 방지망이다(DeployScriptOrderTest 와 같은 방식).
 *
 * @internal
 */
final class DeployWorkflowTest extends CIUnitTestCase
{
    private string $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $path = ROOTPATH . '.github/workflows/deploy.yml';
        $this->assertFileExists($path);
        $this->workflow = (string) file_get_contents($path);
    }

    /** 스텝 하나의 블록(다음 `- name:` 직전까지)을 잘라 낸다. */
    private function stepBlock(string $name): string
    {
        $start = strpos($this->workflow, "- name: {$name}");
        $this->assertNotFalse($start, "워크플로에 '{$name}' 스텝이 있어야 한다.");

        $next = strpos($this->workflow, '- name: ', $start + 1);

        return $next === false
            ? substr($this->workflow, $start)
            : substr($this->workflow, $start, $next - $start);
    }

    /**
     * smoke test 는 배포 뒤에 있어야 한다.
     *
     * 앞서면 배포 "전" 상태를 검사하게 되어 항상 통과한다 — 조용한 무동작이다.
     */
    public function testSmokeTestRunsAfterDeploy(): void
    {
        $deploy = strpos($this->workflow, '- name: Deploy over SSH');
        $smoke  = strpos($this->workflow, '- name: Smoke test');

        $this->assertNotFalse($deploy, '워크플로가 SSH 배포를 실행해야 한다.');
        $this->assertNotFalse($smoke, '워크플로가 smoke test 를 실행해야 한다.');
        $this->assertLessThan($smoke, $deploy, 'smoke test 는 배포보다 뒤에 있어야 한다.');
    }

    /**
     * 체크아웃이 smoke test 보다 앞서야 한다.
     *
     * Deploy 잡은 원래 저장소를 체크아웃하지 않았다. 없으면 러너에
     * scripts/smoke.sh 자체가 존재하지 않는다.
     */
    public function testCheckoutPrecedesSmokeTest(): void
    {
        $checkout = strpos($this->workflow, 'actions/checkout');
        $smoke    = strpos($this->workflow, '- name: Smoke test');

        $this->assertNotFalse($checkout, 'Deploy 잡이 저장소를 체크아웃해야 한다.');
        $this->assertLessThan($smoke, $checkout, '체크아웃은 smoke test 보다 앞서야 한다.');
    }

    public function testSmokeTestRunsAgainstProductionUrl(): void
    {
        $this->assertStringContainsString(
            './scripts/smoke.sh https://blog.unwanted.me',
            $this->workflow,
            'smoke test 는 운영 도메인을 검사해야 한다.'
        );
    }

    /**
     * smoke test 실패는 잡 실패여야 한다.
     *
     * continue-on-error 가 붙으면 실패해도 잡이 초록이 되어 이 기능의 존재
     * 이유가 사라진다. 다른 배선과 달리 이건 붙어도 아무 증상이 없으면서
     * 기능만 무력화하므로, 부재를 직접 단언한다.
     */
    public function testSmokeTestFailureFailsTheJob(): void
    {
        $this->assertStringNotContainsString(
            'continue-on-error',
            $this->stepBlock('Smoke test'),
            'smoke test 실패는 배포 실패로 이어져야 한다.'
        );
    }

    /** 실패했을 때만 진단을 모은다. */
    public function testDiagnosticsRunOnFailure(): void
    {
        $block = $this->stepBlock('Collect diagnostics');

        $this->assertStringContainsString('if: failure()', $block);
        $this->assertStringContainsString('continue-on-error: true', $block);
        $this->assertStringContainsString('writable/logs', $block);
    }
}
