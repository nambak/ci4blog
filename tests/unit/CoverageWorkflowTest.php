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

    /**
     * 수치가 실행 페이지에 보여야 한다.
     *
     * 스크립트와 GITHUB_STEP_SUMMARY 가 job 어딘가에 각각 있다는 것만으로는
     * 부족하다 — 리다이렉트가 사라지거나 두 문자열이 서로 다른 스텝에 흩어져
     * 있어도 그 단언은 통과한다. 요약 스크립트의 출력이 실제로
     * GITHUB_STEP_SUMMARY 로 리다이렉트되는지 정규식으로 못 박는다.
     */
    public function testWritesJobSummary(): void
    {
        $this->assertMatchesRegularExpression(
            '/coverage-summary\.php\s+\S+\s+>>\s*"?\$(\{)?GITHUB_STEP_SUMMARY/m',
            $this->job,
            '요약 스크립트 출력이 Job Summary 로 리다이렉트돼야 한다.'
        );
    }

    /** 상세 리포트를 받아 볼 수 있어야 한다. */
    public function testUploadsHtmlReport(): void
    {
        $this->assertStringContainsString('actions/upload-artifact', $this->job);
        $this->assertStringContainsString('build/logs/html', $this->job);
    }

    /**
     * 타임아웃이 없으면 기본 360분이다. 워크플로 레벨 concurrency
     * (deploy-production, cancel-in-progress: false)와 겹치면, 이 job이
     * 걸려 있는 동안 다음 push 의 배포가 그만큼 대기열에 갇힌다.
     */
    public function testLimitsRunawayTimeToProtectTheDeployQueue(): void
    {
        $this->assertSame(
            1,
            preg_match('/^\s+timeout-minutes:\s*(\d+)/m', $this->job, $matches),
            'coverage job 에 timeout-minutes 가 있어야 한다 — 없으면 기본 360분이다.'
        );
        $this->assertLessThanOrEqual(
            60,
            (int) $matches[1],
            'timeout-minutes 가 너무 크면 사실상 무제한과 같아 존재 이유가 사라진다.'
        );
    }

    /**
     * 이 job 의 GITHUB_TOKEN 은 읽기만 할 수 있어야 한다.
     *
     * 선언이 없으면 저장소·조직 기본값을 그대로 받는다 — 그 기본값이 나중에
     * 넓어지면 이 job 도 조용히 함께 넓어진다. 하는 일은 체크아웃·테스트·요약·
     * 아티팩트가 전부라 쓰기 권한이 필요 없다.
     *
     * 워크플로 레벨이 아니라 **job 레벨**이어야 한다 — 위에 두면 deploy job 까지
     * 함께 묶여, 이 테스트가 통과하면서도 배포 쪽 권한을 예상 밖으로 바꾼다.
     */
    public function testGrantsOnlyReadPermission(): void
    {
        $this->assertSame(
            1,
            preg_match('/^ {4}permissions:\n((?:^ {6}\S.*\n)+)/m', $this->job, $matches),
            'coverage job 에 permissions 블록이 있어야 한다 — 없으면 저장소 기본값을 그대로 받는다.'
        );

        // 블록 안을 통째로 비교한다. job 텍스트 전체에서 'write' 를 찾는 식으로
        // 쓰면 "Write coverage summary" 같은 스텝 이름과 대소문자로만 갈려,
        // 권한과 무관한 이유로 깨지거나 통과한다.
        $granted = array_values(array_filter(array_map('trim', explode("\n", $matches[1]))));

        $this->assertSame(
            ['contents: read'],
            $granted,
            'coverage job 은 contents: read 하나만 가져야 한다 — 쓰기 권한이 필요 없다.'
        );
    }

    /**
     * PHPUnit 은 테스트 실패 시에도 clover/html 리포트를 쓴다. 앞 스텝
     * (Run tests with coverage)이 실패하면 뒤의 두 스텝이 스킵되던 것을
     * if: always() 로 고쳤다 — 실패한 순간이야말로 리포트가 가장 보고 싶을
     * 때다.
     */
    public function testWritesSummaryAndUploadsReportEvenWhenTestsFail(): void
    {
        $this->assertMatchesRegularExpression(
            '/name:\s*Write coverage summary\s*\n\s*if:\s*always\(\)/',
            $this->job,
            'Write coverage summary 스텝은 앞 스텝이 실패해도 실행돼야 한다.'
        );
        $this->assertMatchesRegularExpression(
            '/name:\s*Upload HTML report\s*\n\s*if:\s*always\(\)/',
            $this->job,
            'Upload HTML report 스텝은 앞 스텝이 실패해도 실행돼야 한다.'
        );
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
