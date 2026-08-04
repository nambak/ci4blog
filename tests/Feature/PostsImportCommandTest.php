<?php

namespace Tests\Feature;

use App\Commands\PostsImport;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;

/**
 * php spark posts:import — content/posts/*.md 를 slug 기준으로 업서트. (#133)
 *
 * 이 커맨드는 커버리지 0% 였다. 운영에서는 원고가 .gitignore 라 서버에 없고,
 * 로컬 원고 30개에는 front matter 가 없어 전부 건너뛴다 — 즉 지금은 어디서도
 * 실제로 동작하지 않는다. 나중에 파이프라인을 다시 켰을 때 안전하게 돌도록
 * 명세를 회귀 테스트로 고정한다.
 *
 * command() 는 CLI::write() 출력을 잡지 못한다(STDOUT 직접 쓰기) —
 * DbPruneCommandTest·DbBackupCommandTest 와 같이 StreamFilterTrait 로 가로챈다.
 * 커맨드는 command() 대신 run($params) 로 직접 호출한다. run() 이 $params 를
 * CLI::getOption() 보다 먼저 보므로 CLI 인자 없이 옵션을 제어할 수 있다.
 *
 * @internal
 */
final class PostsImportCommandTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $namespace = null;
    protected $refresh   = true;

    /** 픽스처 .md 를 담는 임시 디렉터리. */
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureDir = sys_get_temp_dir() . '/ci4blog-posts-' . bin2hex(random_bytes(6));
        mkdir($this->fixtureDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeFixtures();
        parent::tearDown();
    }

    private function removeFixtures(): void
    {
        if (! is_dir($this->fixtureDir)) {
            return;
        }

        foreach (glob($this->fixtureDir . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->fixtureDir);
    }

    /**
     * 원고 디렉터리 seam 만 픽스처로 돌린 커맨드.
     *
     * 나머지는 실제 PostsImport 그대로다 — 파싱·upsert 로직을 목으로 바꾸지
     * 않아야 테스트가 진짜 동작을 검증한다.
     */
    private function importer(?string $dir = null): PostsImport
    {
        $command = new class (Services::logger(), Services::commands()) extends PostsImport {
            public string $dir = '';

            protected function contentDir(): string
            {
                return $this->dir;
            }
        };

        $command->dir = $dir ?? $this->fixtureDir;

        return $command;
    }

    /** 픽스처 파일을 만든다. */
    private function writeMarkdown(string $name, string $contents): void
    {
        file_put_contents($this->fixtureDir . '/' . $name, $contents);
    }

    /**
     * front matter + 본문 형태의 원고 문자열.
     *
     * @param array<string, string> $frontMatter
     */
    private function post(array $frontMatter, string $body): string
    {
        $lines = [];

        foreach ($frontMatter as $key => $value) {
            $lines[] = $key . ': ' . $value;
        }

        return "---\n" . implode("\n", $lines) . "\n---\n" . $body;
    }

    /**
     * posts 테이블 전체를 slug 순으로.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return db_connect()->table('posts')->orderBy('slug')->get()->getResultArray();
    }

    /**
     * 원고 디렉터리가 없으면 실패로 끝나야 한다.
     *
     * 조용히 성공하면 배포가 초록인데 아무것도 발행되지 않은 상태가 된다.
     */
    public function testFailsWhenContentDirectoryIsMissing(): void
    {
        $missing = $this->fixtureDir . '/no-such-dir';

        $status = $this->importer($missing)->run([]);

        $this->assertSame(EXIT_ERROR, $status, '디렉터리가 없으면 EXIT_ERROR 여야 한다.');
        $this->assertStringContainsString($missing, $this->getStreamFilterBuffer(), '없는 경로를 출력해야 한다.');
        $this->assertSame([], $this->rows(), '실패했으면 글이 들어가지 않아야 한다.');
    }

    /** 대상 파일이 없는 것은 실패가 아니다(운영이 지금 매번 밟는 경로). */
    public function testSucceedsWhenNoFilesMatch(): void
    {
        $status = $this->importer()->run([]);

        $this->assertSame(EXIT_SUCCESS, $status, '대상이 없는 것은 실패가 아니다.');
        $this->assertStringContainsString('대상 파일이 없습니다', $this->getStreamFilterBuffer());
        $this->assertSame([], $this->rows());
    }
}
