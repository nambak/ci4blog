<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * 문서가 실제 저장소·운영 구성과 어긋나는 것을 막는다. (#116)
 *
 * 이번에 실제로 발견된 세 가지만 겨냥한다 — 저장소에 없는 경로를 구조도가
 * 안내하고 있었고(`docs/curriculum.md`), PHP 버전이 composer.json 과 달랐고
 * (8.1 vs ^8.2), 운영 .env 예시가 MySQL 기준이었다(운영은 SQLite).
 *
 * 문서 표현을 바꿀 때마다 깨지는 검사는 넣지 않는다 — 그런 테스트는 결국
 * 주석 처리되고, 그러면 아무것도 막지 못한다.
 *
 * @internal
 */
final class ReadmeDriftTest extends CIUnitTestCase
{
    private string $readme;

    protected function setUp(): void
    {
        parent::setUp();

        $path = ROOTPATH . 'README.md';
        $this->assertFileExists($path);
        $this->readme = (string) file_get_contents($path);
    }

    /**
     * 구조도의 경로는 저장소에 실재해야 한다.
     *
     * 구조도는 "클론하면 보이는 것" 을 보여 주는 그림이다. 사라진 파일을
     * 안내하면 처음 온 사람이 없는 곳을 찾게 된다.
     */
    public function testStructureDiagramPathsExist(): void
    {
        $paths = $this->structurePaths();

        $this->assertNotEmpty($paths, '구조도에서 경로를 하나도 못 읽었다면 파싱이 깨진 것이다.');

        foreach ($paths as $path) {
            $this->assertFileExists(
                ROOTPATH . $path,
                "구조도가 안내하는 {$path} 가 저장소에 없다."
            );
        }
    }

    /**
     * 구조도의 경로는 저장소(HEAD)에 추적돼 있어야 한다.
     *
     * 위 테스트와 나누어 둔 이유가 있다 — `docs/` 처럼 **로컬에는 있지만
     * 저장소에는 없는** 경로는 존재 검사만으로는 걸리지 않는다.
     *
     * `git check-ignore` 로 묻지 않는다. 그 명령은 무시될 때만 0 을 주는데,
     * 저장소 밖이거나 git 이 없어 실패해도 0 이 아니라 **거짓 통과**한다.
     * `ls-tree` 로 "HEAD 에 항목이 있는가" 를 직접 묻고, 명령 실패는 실패로 본다.
     */
    public function testStructureDiagramPathsAreTracked(): void
    {
        $paths = $this->structurePaths();

        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            [$entries, $exitCode] = $this->gitLsTree($path);

            $this->assertSame(
                0,
                $exitCode,
                "git ls-tree 가 실패했다({$path}) — 결과를 신뢰할 수 없다."
            );
            $this->assertNotEmpty(
                $entries,
                "{$path} 가 HEAD 에 없다 — 클론한 사람에게는 보이지 않으므로 구조도에서 빼야 한다."
            );
        }
    }

    /**
     * README 가 말하는 PHP 버전이 composer.json 의 요구와 같아야 한다.
     *
     * 굵게 표시(`**PHP**`)나 공백 같은 서식에 기대지 않는다 — 서식만 바꿔도
     * 깨지는 검사는 이 클래스가 피하려는 바로 그것이다. "PHP … N.N 이상" 이라고
     * 말하는 **모든** 자리를 찾아 전부 composer 와 같은지 본다.
     */
    public function testPhpVersionMatchesComposer(): void
    {
        $composer = json_decode((string) file_get_contents(ROOTPATH . 'composer.json'), true);
        $require  = $composer['require']['php'] ?? '';

        $this->assertSame(
            1,
            preg_match('/(\d+\.\d+)/', $require, $matches),
            "composer.json 의 require.php 에서 버전을 못 읽었다: {$require}"
        );

        $version = $matches[1];

        $found = preg_match_all('/PHP[^\n\d]*(\d+\.\d+)\s*이상/u', $this->readme, $mentions);

        $this->assertNotSame(false, $found);
        $this->assertGreaterThanOrEqual(
            2,
            $found,
            'README 는 기술 스택과 사전 준비물 두 곳에서 PHP 버전을 말해야 한다.'
        );

        foreach ($mentions[1] as $mentioned) {
            $this->assertSame(
                $version,
                $mentioned,
                "README 가 PHP {$mentioned} 이상이라 하는데 composer.json 은 {$require} 를 요구한다."
            );
        }
    }

    /** 운영 .env 예시는 운영과 같은 드라이버여야 한다. */
    public function testProductionEnvExampleUsesSqlite(): void
    {
        $env = $this->productionEnv();

        $this->assertMatchesRegularExpression(
            '/^database\.default\.DBDriver\s*=\s*SQLite3\s*$/m',
            $env,
            '운영은 SQLite 다 — 예시가 다른 드라이버를 기본값으로 두면 그대로 복사한 서버가 어긋난다.'
        );
    }

    /**
     * DB 파일은 절대경로이고 웹 루트 밖이어야 한다.
     *
     * 드라이버만 맞고 경로가 상대경로거나 `public/` 아래면, 그대로 복사한 서버가
     * 엉뚱한 곳에 DB 를 만들거나 DB 파일이 그대로 다운로드된다.
     */
    public function testProductionEnvExampleKeepsDatabaseOutsideWebRoot(): void
    {
        $env = $this->productionEnv();

        $this->assertSame(
            1,
            preg_match('/^database\.default\.database\s*=\s*(\S+)\s*$/m', $env, $matches),
            'env.production.example 에 database.default.database 가 있어야 한다.'
        );

        $dbPath = $matches[1];

        $this->assertStringStartsWith(
            '/',
            $dbPath,
            "DB 경로는 절대경로여야 한다(현재: {$dbPath}) — 상대경로는 실행 위치에 따라 달라진다."
        );
        $this->assertStringNotContainsString(
            '/public/',
            $dbPath,
            "DB 파일이 웹 루트 안에 있으면 그대로 다운로드된다(현재: {$dbPath})."
        );
    }

    /** 운영 .env 예시 원문. */
    private function productionEnv(): string
    {
        $path = ROOTPATH . 'env.production.example';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * HEAD 에서 그 경로 아래 추적 항목을 나열한다.
     *
     * @return array{0: list<string>, 1: int} [항목 목록, 종료 코드]
     */
    private function gitLsTree(string $path): array
    {
        $output   = [];
        $exitCode = 0;

        exec(
            'git -C ' . escapeshellarg(rtrim(ROOTPATH, '/'))
                . ' ls-tree -r --name-only HEAD -- ' . escapeshellarg($path) . ' 2>/dev/null',
            $output,
            $exitCode
        );

        return [$output, $exitCode];
    }

    /**
     * 구조도 코드블록에서 경로를 뽑는다.
     *
     * 트리 그림(├─ └─ │)에서 들여쓰기 깊이로 부모를 추적해 전체 경로를 만든다.
     * 주석(`# …`)과 장식 문자는 버린다.
     *
     * @return list<string>
     */
    private function structurePaths(): array
    {
        $this->assertSame(
            1,
            preg_match('/## 프로젝트 구조\s*\n+```text\n(.*?)```/s', $this->readme, $matches),
            'README 에 "## 프로젝트 구조" 의 text 코드블록이 있어야 한다.'
        );

        $paths  = [];
        $parent = [];

        foreach (explode("\n", $matches[1]) as $line) {
            if (trim($line) === '' || ! str_contains($line, '─')) {
                continue;
            }

            // 장식 앞부분의 길이로 깊이를 잰다(한 단계 = 3칸).
            $position = strpos($line, '├─');

            if ($position === false) {
                $position = strpos($line, '└─');
            }

            if ($position === false) {
                continue;
            }

            $depth = intdiv($position, 3);
            $name  = trim(substr($line, $position + strlen('├─')));
            $name  = trim(explode('#', $name)[0]);
            $name  = rtrim($name, '/');

            if ($name === '') {
                continue;
            }

            $parent         = array_slice($parent, 0, $depth);
            $parent[$depth] = $name;
            $paths[]        = implode('/', $parent);
        }

        return $paths;
    }
}
