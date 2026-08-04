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
     * 구조도가 gitignore 된 경로를 안내하면 안 된다.
     *
     * 위 테스트와 나누어 둔 이유가 있다 — `docs/` 처럼 **로컬에는 있지만
     * 저장소에는 없는** 경로는 존재 검사만으로는 걸리지 않는다.
     */
    public function testStructureDiagramHasNoIgnoredPaths(): void
    {
        $paths = $this->structurePaths();

        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            $output   = [];
            $exitCode = 0;
            exec(
                'git -C ' . escapeshellarg(rtrim(ROOTPATH, '/'))
                    . ' check-ignore ' . escapeshellarg($path) . ' 2>/dev/null',
                $output,
                $exitCode
            );

            // check-ignore 는 무시되는 경로일 때 0 을 돌려준다.
            $this->assertNotSame(
                0,
                $exitCode,
                "{$path} 는 gitignore 대상이라 클론한 사람에게는 없다 — 구조도에서 빼야 한다."
            );
        }
    }

    /** README 가 말하는 PHP 버전이 composer.json 의 요구와 같아야 한다. */
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

        $this->assertStringContainsString(
            "PHP** {$version} 이상",
            $this->readme,
            "README 의 기술 스택이 composer.json({$require})과 다른 PHP 버전을 말한다."
        );
        $this->assertStringContainsString(
            "PHP {$version} 이상",
            $this->readme,
            "README 의 사전 준비물이 composer.json({$require})과 다른 PHP 버전을 말한다."
        );
    }

    /** 운영 .env 예시는 운영과 같은 드라이버여야 한다. */
    public function testProductionEnvExampleUsesSqlite(): void
    {
        $path = ROOTPATH . 'env.production.example';
        $this->assertFileExists($path);

        $env = (string) file_get_contents($path);

        $this->assertMatchesRegularExpression(
            '/^database\.default\.DBDriver\s*=\s*SQLite3\s*$/m',
            $env,
            '운영은 SQLite 다 — 예시가 다른 드라이버를 기본값으로 두면 그대로 복사한 서버가 어긋난다.'
        );
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
