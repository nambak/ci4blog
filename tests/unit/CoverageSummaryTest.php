<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * 커버리지 요약 스크립트. (#115)
 *
 * clover.xml 을 GitHub Actions Job Summary 용 마크다운으로 바꾼다. 워크플로
 * 인라인 쉘이 아니라 스크립트로 분리한 이유가 이 테스트다 — 실제로 실행해서
 * 계산과 서식을 검증한다(SmokeScriptTest 와 같은 방식).
 *
 * @internal
 */
final class CoverageSummaryTest extends CIUnitTestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = ROOTPATH . 'scripts/coverage-summary.php';
        $this->assertFileExists($this->path);
    }

    /**
     * 스크립트를 실제로 실행한다.
     *
     * @param string|null $clover null 이면 인자 없이 실행한다.
     *
     * @return array{output: string, exit: int}
     */
    private function runSummary(?string $clover): array
    {
        $tmp = null;
        $cmd = 'php ' . escapeshellarg($this->path);

        if ($clover !== null) {
            // 확장자를 덧붙이지 않는다 — tempnam 이 만든 파일을 그대로 써야
            // 임시 파일이 남지 않는다.
            $tmp = (string) tempnam(sys_get_temp_dir(), 'clover');
            file_put_contents($tmp, $clover);
            $cmd .= ' ' . escapeshellarg($tmp);
        }

        try {
            // stderr 까지 봐야 usage 와 실패 사유를 확인할 수 있다.
            exec($cmd . ' 2>&1', $lines, $exitCode);
        } finally {
            if ($tmp !== null) {
                unlink($tmp);
            }
        }

        return ['output' => implode("\n", $lines), 'exit' => $exitCode];
    }

    /**
     * 고정 픽스처. 기대값을 하드코딩할 수 있도록 숫자를 단순하게 잡았다.
     *
     * low  : 줄 1/4  (25.0%)  · 메서드 2/2
     * high : 줄 3/4  (75.0%)  · 메서드 1/2
     * empty: 줄 0/0            · 표에서 빠져야 한다
     *
     * 합계 : 줄 4/8 = 50.0% · 메서드 3/4 = 75.0%
     */
    private function fixture(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <coverage generated="1785754917">
              <project timestamp="1785754917">
                <file name="/srv/app/Commands/LowCovered.php">
                  <metrics loc="40" ncloc="30" classes="1" methods="2" coveredmethods="2" statements="4" coveredstatements="1" elements="6" coveredelements="3"/>
                </file>
                <file name="/srv/app/Models/HighCovered.php">
                  <metrics loc="40" ncloc="30" classes="1" methods="2" coveredmethods="1" statements="4" coveredstatements="3" elements="6" coveredelements="4"/>
                </file>
                <file name="/srv/app/Config/EmptyOne.php">
                  <metrics loc="5" ncloc="3" classes="1" methods="0" coveredmethods="0" statements="0" coveredstatements="0" elements="0" coveredelements="0"/>
                </file>
                <metrics files="3" statements="8" coveredstatements="4" methods="4" coveredmethods="3"/>
              </project>
            </coverage>
            XML;
    }

    /**
     * 비율이 정확해야 한다.
     *
     * 기대값은 픽스처를 보고 **손으로 계산해** 박았다. 스크립트와 같은 식으로
     * 만들면 식이 틀려도 함께 틀려서 통과한다(위양성).
     *
     * ⚠️ 부분 문자열로 '75.0%' 만 찾으면 안 된다 — 저커버리지 표의 HighCovered
     * 행도 3/4 = 75.0% 라 총계가 틀려도 그 행에 걸려 통과한다. **행 전체**를
     * 매칭해 총계 행임을 못 박는다.
     */
    public function testCalculatesLineAndMethodRatios(): void
    {
        $result = $this->runSummary($this->fixture());

        $this->assertSame(0, $result['exit'], "정상 입력은 종료코드 0이어야 한다. 출력: {$result['output']}");
        $this->assertMatchesRegularExpression(
            '/^\| 줄 \| 4 \| 8 \| 50\.0% \|$/m',
            $result['output'],
            "줄 커버리지 총계는 4/8 = 50.0% 여야 한다. 출력: {$result['output']}"
        );
        $this->assertMatchesRegularExpression(
            '/^\| 메서드 \| 3 \| 4 \| 75\.0% \|$/m',
            $result['output'],
            "메서드 커버리지 총계는 3/4 = 75.0% 여야 한다. 출력: {$result['output']}"
        );
    }

    /**
     * 총계는 파일별 값의 합이어야 한다(두 출처가 어긋나면 요약을 못 믿는다).
     *
     * 픽스처의 <project><metrics> 는 일부러 파일 합과 같게 두었다. 스크립트가
     * 그쪽을 읽든 파일을 합산하든 이 테스트만으로는 구분되지 않으므로,
     * 구분은 아래 testIgnoresProjectMetrics 가 맡는다.
     */
    public function testTotalsMatchFileSum(): void
    {
        $output = $this->runSummary($this->fixture())['output'];

        $lines   = 1 + 3;  // LowCovered 1 + HighCovered 3
        $total   = 4 + 4;  // 두 파일의 statements
        $methods = 2 + 1;  // coveredmethods
        $allM    = 2 + 2;  // methods

        $this->assertMatchesRegularExpression("/^\\| 줄 \\| {$lines} \\| {$total} \\|/m", $output);
        $this->assertMatchesRegularExpression("/^\\| 메서드 \\| {$methods} \\| {$allM} \\|/m", $output);
    }

    /**
     * <project><metrics> 가 아니라 <file> 들을 합산해야 한다.
     *
     * 파일별 표와 총계가 다른 출처에서 나오면 서로 어긋날 수 있다. project
     * metrics 에 엉뚱한 값을 넣은 픽스처로 어느 쪽을 읽는지 가른다.
     */
    public function testIgnoresProjectMetrics(): void
    {
        $clover = str_replace(
            '<metrics files="3" statements="8" coveredstatements="4" methods="4" coveredmethods="3"/>',
            '<metrics files="3" statements="999" coveredstatements="999" methods="999" coveredmethods="999"/>',
            $this->fixture()
        );

        $output = $this->runSummary($clover)['output'];

        $this->assertStringNotContainsString('999', $output, 'project metrics 가 아니라 파일별 값을 합산해야 한다.');
        $this->assertMatchesRegularExpression('/^\| 줄 \| 4 \| 8 \| 50\.0% \|$/m', $output);
    }

    /** 커버리지가 낮은 파일이 위에 와야 한다 — 이 순서가 표의 존재 이유다. */
    public function testListsLowestCoverageFirst(): void
    {
        $output = $this->runSummary($this->fixture())['output'];

        $low  = strpos($output, 'LowCovered.php');
        $high = strpos($output, 'HighCovered.php');

        $this->assertNotFalse($low, '낮은 커버리지 파일이 표에 있어야 한다.');
        $this->assertNotFalse($high, '높은 커버리지 파일도 표에 있어야 한다.');
        $this->assertLessThan($high, $low, '커버리지가 낮은 파일이 먼저 나와야 한다.');
    }

    /**
     * 실행 가능한 줄이 없는 파일은 표에서 뺀다.
     *
     * 0/0 을 0% 로 표시하면 순위표 맨 위를 인터페이스·설정 클래스가 차지해
     * 진짜 공백이 밀려난다.
     *
     * 종료코드를 먼저 단언하는 이유: 가드가 사라지면 0/0 나눗셈이 표에 새는 게
     * 아니라 DivisionByZeroError 로 스크립트가 죽는다. 그러면 출력이 비어
     * 아래 음성 단언이 공허하게 통과해 버린다.
     */
    public function testSkipsFilesWithNoStatements(): void
    {
        $result = $this->runSummary($this->fixture());

        $this->assertSame(
            0,
            $result['exit'],
            "정상 입력은 종료코드 0 이어야 한다 — 스크립트가 죽으면 아래 음성 단언이 공허하게 통과한다. 출력: {$result['output']}"
        );
        $this->assertStringNotContainsString(
            'EmptyOne.php',
            $result['output'],
            '실행 가능 줄이 0인 파일은 표에 없어야 한다.'
        );
    }

    /** 프로젝트 루트 접두어를 떼어 읽기 쉬운 경로로 보여 준다. */
    public function testStripsProjectRootFromPaths(): void
    {
        $clover = str_replace('/srv/', rtrim(ROOTPATH, '/') . '/', $this->fixture());

        $output = $this->runSummary($clover)['output'];

        $this->assertStringContainsString('app/Commands/LowCovered.php', $output);
        $this->assertStringNotContainsString(rtrim(ROOTPATH, '/') . '/app', $output, '절대경로가 그대로 남으면 안 된다.');
    }

    /** 리포트 경로는 필수다 — 기본값을 두면 엉뚱한 파일을 조용히 요약한다. */
    public function testRequiresCloverPathArgument(): void
    {
        $result = $this->runSummary(null);

        $this->assertSame(1, $result['exit'], "인자 없이 실행하면 종료코드 1이어야 한다. 출력: {$result['output']}");
        $this->assertStringContainsString('usage:', $result['output']);
    }

    /** 없는 경로를 주면 조용히 빈 요약을 내지 말고 실패해야 한다. */
    public function testFailsOnMissingFile(): void
    {
        $missing = sys_get_temp_dir() . '/ci4blog-no-such-clover-' . bin2hex(random_bytes(4)) . '.xml';

        $cmd = 'php ' . escapeshellarg($this->path) . ' ' . escapeshellarg($missing) . ' 2>&1';
        exec($cmd, $lines, $exitCode);

        $this->assertSame(1, $exitCode, '없는 파일이면 종료코드 1이어야 한다. 출력: ' . implode("\n", $lines));
    }

    /** 깨진 XML 도 실패로 끝나야 한다. */
    public function testFailsOnMalformedXml(): void
    {
        $result = $this->runSummary('<coverage><project>깨진');

        $this->assertSame(1, $result['exit'], "파싱 실패는 종료코드 1이어야 한다. 출력: {$result['output']}");
    }
}
