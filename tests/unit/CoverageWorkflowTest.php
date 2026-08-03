<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * 커버리지 측정 배선. (#115)
 *
 * 커버리지는 **배포를 막지 않는다**는 것이 이 job 의 계약이다. 그 계약과
 * 배선이 조용히 뒤집히는 것을 막는 회귀 방지망이다(DeployWorkflowTest 와 같은 방식).
 *
 * @internal
 */
final class CoverageWorkflowTest extends CIUnitTestCase
{
    private string $workflow;

    /** coverage 잡 부분만 담는다. */
    private string $job;

    protected function setUp(): void
    {
        parent::setUp();

        $path = ROOTPATH . '.github/workflows/deploy.yml';
        $this->assertFileExists($path);
        $this->workflow = (string) file_get_contents($path);

        $start = strpos($this->workflow, "\n  coverage:");
        $this->assertNotFalse($start, '워크플로에 coverage 잡이 있어야 한다.');

        // 파일 전체를 보면 안 된다 — 'actions/checkout' 이나 'composer install' 은
        // 다른 잡에도 있어서, coverage 잡에서 지워도 다른 잡의 것이 잡혀 통과한다.
        $end = strpos($this->workflow, "\n  deploy:", $start);
        $this->assertNotFalse($end, 'coverage 잡은 deploy 잡보다 앞에 있어야 한다.');

        $this->job = substr($this->workflow, $start, $end - $start);
    }

    /** 드라이버가 없으면 커버리지가 아예 산출되지 않는다. */
    public function testEnablesPcovDriver(): void
    {
        $this->assertStringContainsString('coverage: pcov', $this->job);
    }

    /** 커버리지 잡에서 커버리지를 끄면 존재 이유가 사라진다. */
    public function testDoesNotDisableCoverage(): void
    {
        $this->assertStringNotContainsString('--no-coverage', $this->job);
    }

    /** 수치가 실행 페이지에 보여야 한다. */
    public function testWritesJobSummary(): void
    {
        $this->assertStringContainsString('scripts/coverage-summary.php', $this->job);
        $this->assertStringContainsString('GITHUB_STEP_SUMMARY', $this->job);
    }

    /** 상세 리포트를 받아 볼 수 있어야 한다. */
    public function testUploadsHtmlReport(): void
    {
        $this->assertStringContainsString('actions/upload-artifact', $this->job);
        $this->assertStringContainsString('build/logs/html', $this->job);
    }

    /**
     * coverage 잡은 deploy 잡보다 **앞에** 있어야 한다.
     *
     * DeployWorkflowTest::setUp() 이 "\n  deploy:" 이후 파일 끝까지를 deploy 잡으로
     * 보기 때문이다. 뒤에 두면 그 테스트가 이 잡까지 검사해 위양성이 된다.
     */
    public function testCoverageJobPrecedesDeployJob(): void
    {
        $coverage = strpos($this->workflow, "\n  coverage:");
        $deploy   = strpos($this->workflow, "\n  deploy:");

        $this->assertNotFalse($coverage);
        $this->assertNotFalse($deploy);
        $this->assertLessThan($deploy, $coverage, 'coverage 잡이 deploy 잡보다 앞에 있어야 한다.');
    }

    /** 배포는 커버리지를 기다리지 않는다 — 이 job 이 깨져도 배포는 나간다. */
    public function testDeployDoesNotWaitForCoverage(): void
    {
        $deployStart = strpos($this->workflow, "\n  deploy:");
        $this->assertNotFalse($deployStart);
        $deploy = substr($this->workflow, $deployStart);

        $this->assertSame(
            1,
            preg_match('/^\s+needs:\s*(.+)$/m', $deploy, $matches),
            'deploy 잡에 needs 가 있어야 한다.'
        );
        $this->assertStringNotContainsString(
            'coverage',
            $matches[1],
            'deploy 는 coverage 를 기다리면 안 된다(커버리지가 배포를 막게 된다).'
        );
    }

    /**
     * 배포 게이트인 test 잡은 그대로 둔다.
     *
     * 커버리지를 test 잡에 합치면 그 오버헤드가 배포 경로에 그대로 얹힌다.
     */
    public function testGateJobStillRunsWithoutCoverage(): void
    {
        $start = strpos($this->workflow, "\n  test:");
        $end   = strpos($this->workflow, "\n  coverage:");

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $test = substr($this->workflow, $start, $end - $start);

        $this->assertStringContainsString('--no-coverage', $test, 'test 잡은 커버리지 없이 돌아야 한다.');
        $this->assertStringContainsString('coverage: none', $test);
    }
}
