<?php

/**
 * clover.xml 을 GitHub Actions Job Summary 용 마크다운으로 바꾼다. (#115)
 *
 * 사용 예:
 *   php scripts/coverage-summary.php build/logs/clover.xml >> "$GITHUB_STEP_SUMMARY"
 *
 * 설계 메모:
 *  - 총계는 <project><metrics> 를 믿지 않고 <file> 들을 직접 합산한다. 파일별 표와
 *    총계가 같은 출처에서 나와야 서로 어긋날 수 없다.
 *  - 실행 가능한 줄이 0 인 파일은 표에서 뺀다. 0/0 을 0% 로 적으면 순위표 위쪽을
 *    인터페이스·설정 클래스가 차지해 진짜 공백이 밀려난다.
 *  - 비율은 게이트가 아니다. 이 스크립트는 수치를 보여 줄 뿐 종료코드로 판정하지
 *    않는다(배포를 막지 않는다는 계약). 다만 합산된 statements 가 0 이면 얘기가
 *    다르다 — 이는 "커버리지가 낮다"가 아니라 "입력이 퇴화했다"는 뜻이고, 파일
 *    없음·XML 파싱 실패와 같은 부류의 오류다(예: pcov.directory 휴리스틱이
 *    빗나가 아무 것도 계측되지 않은 경우). 그래서 이 경우만 exit(1) 한다.
 */

/** 표에 싣는 저커버리지 파일 개수. */
const LOW_COVERAGE_LIMIT = 10;

$path = $argv[1] ?? null;

if ($path === null) {
    fwrite(STDERR, "usage: coverage-summary.php <clover.xml>\n");

    exit(1);
}

if (! is_file($path) || ! is_readable($path)) {
    fwrite(STDERR, "커버리지 리포트를 읽을 수 없다: {$path}\n");

    exit(1);
}

$previous = libxml_use_internal_errors(true);
$xml      = simplexml_load_file($path);
libxml_use_internal_errors($previous);

if ($xml === false) {
    fwrite(STDERR, "clover XML 을 파싱할 수 없다: {$path}\n");

    exit(1);
}

$root = dirname(__DIR__) . DIRECTORY_SEPARATOR;

$files            = [];
$statements       = 0;
$coveredStatement = 0;
$methods          = 0;
$coveredMethods   = 0;

foreach ($xml->xpath('//file') ?: [] as $file) {
    // SimpleXML 은 없는 자식에 null 이 아니라 빈 객체를 준다 — null 비교가 아니라
    // isset 으로 물어야 실제로 걸러진다.
    if (! isset($file->metrics)) {
        continue;
    }

    $metrics = $file->metrics;

    $fileStatements = (int) $metrics['statements'];
    $fileCovered    = (int) $metrics['coveredstatements'];

    $statements += $fileStatements;
    $coveredStatement += $fileCovered;
    $methods += (int) $metrics['methods'];
    $coveredMethods += (int) $metrics['coveredmethods'];

    // 실행 가능한 줄이 없으면 비율을 매길 수 없다.
    if ($fileStatements === 0) {
        continue;
    }

    $name = (string) $file['name'];

    if (str_starts_with($name, $root)) {
        $name = substr($name, strlen($root));
    }

    $files[] = [
        'name'      => $name,
        'covered'   => $fileCovered,
        'total'     => $fileStatements,
        'ratio'     => $fileCovered / $fileStatements,
    ];
}

// 이 job 의 존재 이유가 "수치를 보이게 하는 것"인데, 0 이면 초록불에 빈 표만
// 낸다. 예를 들어 shivammathur/setup-php 의 pcov.directory 휴리스틱이 빗나가면
// 드라이버는 켜져도 아무 것도 계측되지 않는다 — 그때 조용히 통과하면 안 된다.
if ($statements === 0) {
    fwrite(STDERR, "커버리지가 한 줄도 수집되지 않았다(드라이버·pcov.directory 확인): {$path}\n");

    exit(1);
}

// 낮은 순. 같으면 구멍이 큰(줄 수가 많은) 쪽을 먼저, 그래도 같으면 이름순 —
// 정렬이 실행마다 흔들리지 않아야 요약을 비교할 수 있다.
usort($files, static function (array $a, array $b): int {
    return [$a['ratio'], -$a['total'], $a['name']] <=> [$b['ratio'], -$b['total'], $b['name']];
});

/** 비율을 소수 첫째 자리 퍼센트로. 분모가 0 이면 '-'. */
$percent = static function (int $covered, int $total): string {
    if ($total === 0) {
        return '-';
    }

    return number_format($covered / $total * 100, 1) . '%';
};

echo "## 커버리지\n\n";
echo "| 지표 | 커버 | 전체 | 비율 |\n";
echo "| --- | ---: | ---: | ---: |\n";
echo '| 줄 | ' . $coveredStatement . ' | ' . $statements . ' | ' . $percent($coveredStatement, $statements) . " |\n";
echo '| 메서드 | ' . $coveredMethods . ' | ' . $methods . ' | ' . $percent($coveredMethods, $methods) . " |\n";

if ($files !== []) {
    $shown = array_slice($files, 0, LOW_COVERAGE_LIMIT);

    echo "\n### 커버리지가 낮은 파일 " . count($shown) . "개\n\n";
    echo "| 파일 | 커버 | 전체 | 비율 |\n";
    echo "| --- | ---: | ---: | ---: |\n";

    foreach ($shown as $file) {
        echo '| `' . $file['name'] . '` | ' . $file['covered'] . ' | ' . $file['total'] . ' | '
            . $percent($file['covered'], $file['total']) . " |\n";
    }
}
