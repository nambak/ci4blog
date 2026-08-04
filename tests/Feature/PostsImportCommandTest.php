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

    /**
     * front matter 가 컬럼에 매핑된다.
     *
     * published_at 은 created_at·updated_at 양쪽에 들어간다(신규 생성 시).
     */
    public function testMapsFrontMatterToColumns(): void
    {
        $this->writeMarkdown('ep07.md', $this->post([
            'title'        => '테스트 회차',
            'slug'         => 'test-episode',
            'published_at' => '2026-01-15 10:30:00',
            'author'       => '7',
        ], "본문 첫 줄\n\n둘째 문단"));

        $status = $this->importer()->run([]);

        $this->assertSame(EXIT_SUCCESS, $status);

        $rows = $this->rows();
        $this->assertCount(1, $rows, '글 한 건이 생성돼야 한다.');

        $row = $rows[0];
        $this->assertSame('테스트 회차', $row['title']);
        $this->assertSame('test-episode', $row['slug']);
        $this->assertSame(7, (int) $row['user_id'], 'front matter 의 author 가 user_id 가 돼야 한다.');
        $this->assertSame('2026-01-15 10:30:00', $row['created_at'], 'published_at 이 created_at 이 돼야 한다.');
        $this->assertSame('2026-01-15 10:30:00', $row['updated_at'], '생성 시에는 updated_at 도 published_at 이다.');
    }

    /**
     * front matter 의 slug 를 그대로 쓴다.
     *
     * PostModel 은 저장 시 제목으로 slug 를 자동 생성한다. 이 커맨드가 모델이
     * 아니라 쿼리 빌더로 직접 쓰는 이유가 바로 이것이라, 제목에서 만들어질
     * 법한 slug 와 **다른** 값을 줘서 덮이지 않는지 본다.
     */
    public function testKeepsSlugFromFrontMatter(): void
    {
        $this->writeMarkdown('ep08.md', $this->post([
            'title' => '아주 긴 한글 제목 여덟 번째',
            'slug'  => 'ep08-custom',
        ], '본문'));

        $this->importer()->run([]);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('ep08-custom', $rows[0]['slug'], '제목에서 만든 slug 로 덮이면 안 된다.');
    }

    /**
     * body 는 마크다운 원문 그대로 저장한다(표시용 변환은 Entity 접근자 담당).
     */
    public function testStoresRawMarkdownBody(): void
    {
        $body = "# 헤딩\n\n- 목록 하나\n- 목록 둘\n\n**굵게** 그리고 `코드`";

        $this->writeMarkdown('ep09.md', $this->post([
            'title' => '마크다운 원문',
            'slug'  => 'raw-markdown',
        ], $body));

        $this->importer()->run([]);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame($body, $rows[0]['body'], '마크다운이 HTML 로 변환되면 안 된다.');
        $this->assertStringNotContainsString('<h1', $rows[0]['body']);
        $this->assertStringNotContainsString('<strong', $rows[0]['body']);
    }

    /**
     * slug 기준 멱등 upsert — 두 번 돌려도 결과가 같다.
     *
     * 행 수만 세면 위양성이다(내용이 바뀌어도 통과). 컬럼 값까지 통째로 비교한다.
     */
    public function testIsIdempotentAcrossRuns(): void
    {
        $this->writeMarkdown('ep10.md', $this->post([
            'title'        => '멱등 확인',
            'slug'         => 'idempotent',
            'published_at' => '2026-02-01 09:00:00',
        ], '본문'));

        $this->importer()->run([]);
        $first = $this->rows();

        $this->importer()->run([]);
        $second = $this->rows();

        $this->assertCount(1, $second, '두 번 돌려도 행이 늘면 안 된다.');
        $this->assertSame(
            array_intersect_key($first[0], array_flip(['id', 'title', 'slug', 'body', 'user_id', 'created_at'])),
            array_intersect_key($second[0], array_flip(['id', 'title', 'slug', 'body', 'user_id', 'created_at'])),
            'id·내용·생성시각이 그대로여야 한다.'
        );
    }

    /**
     * 갱신은 내용을 바꾸고 created_at 은 건드리지 않는다.
     *
     * 같은 slug 로 제목·본문을 바꿔 다시 import 한다.
     */
    public function testUpdateChangesContentButKeepsCreatedAt(): void
    {
        $this->writeMarkdown('ep11.md', $this->post([
            'title'        => '처음 제목',
            'slug'         => 'updatable',
            'published_at' => '2026-03-01 08:00:00',
        ], '처음 본문'));

        $this->importer()->run([]);
        $before = $this->rows()[0];

        $this->writeMarkdown('ep11.md', $this->post([
            'title'        => '바뀐 제목',
            'slug'         => 'updatable',
            'published_at' => '2026-03-01 08:00:00',
        ], '바뀐 본문'));

        $this->importer()->run([]);
        $after = $this->rows();

        $this->assertCount(1, $after, '갱신이지 생성이 아니다.');
        $this->assertSame('바뀐 제목', $after[0]['title']);
        $this->assertSame('바뀐 본문', $after[0]['body']);
        $this->assertSame($before['id'], $after[0]['id'], '같은 행이어야 한다.');
        $this->assertSame(
            '2026-03-01 08:00:00',
            $after[0]['created_at'],
            '현재 동작: update 는 created_at 을 재동기화하지 않는다(별도 이슈에서 재검토).'
        );
        $this->assertNotSame(
            '2026-03-01 08:00:00',
            $after[0]['updated_at'],
            '갱신 시각은 front matter 가 아니라 실행 시각으로 바뀐다(현재 동작).'
        );
    }

    /**
     * --dry-run 은 DB 를 건드리지 않는다.
     *
     * "안 바뀐다"만 단언하면 커맨드가 아무것도 안 해도 통과한다. 같은 픽스처를
     * 실제로 실행해 바뀌는 것을 대조로 확인해야 이 테스트가 의미를 갖는다.
     */
    public function testDryRunLeavesDatabaseUntouched(): void
    {
        $this->writeMarkdown('ep12.md', $this->post([
            'title' => '드라이런',
            'slug'  => 'dry-run-post',
        ], '본문'));

        $status = $this->importer()->run(['dry-run' => null]);

        $this->assertSame(EXIT_SUCCESS, $status);
        $this->assertSame([], $this->rows(), 'dry-run 은 DB 를 바꾸지 않아야 한다.');
        $this->assertStringContainsString('[dry-run]', $this->getStreamFilterBuffer());
        $this->assertStringContainsString('dry-run-post', $this->getStreamFilterBuffer(), '무엇이 반영될지는 보여 줘야 한다.');

        // 대조: 같은 픽스처를 실제로 돌리면 들어간다.
        $this->importer()->run([]);
        $this->assertCount(1, $this->rows(), '실제 실행에서는 들어가야 한다(대조군).');
    }

    /** --only 는 지정한 파일만 처리한다. */
    public function testOnlyProcessesTheNamedEpisode(): void
    {
        $this->writeMarkdown('ep13.md', $this->post([
            'title' => '열셋',
            'slug'  => 'ep13-slug',
        ], '본문 13'));
        $this->writeMarkdown('ep14.md', $this->post([
            'title' => '열넷',
            'slug'  => 'ep14-slug',
        ], '본문 14'));

        $this->importer()->run(['only' => 'ep13']);

        $rows = $this->rows();
        $this->assertCount(1, $rows, '지정한 하나만 들어가야 한다.');
        $this->assertSame('ep13-slug', $rows[0]['slug']);
    }

    /** --author 는 front matter 의 author 보다 우선한다. */
    public function testAuthorOptionOverridesFrontMatter(): void
    {
        $this->writeMarkdown('ep15.md', $this->post([
            'title'  => '작성자 덮어쓰기',
            'slug'   => 'author-override',
            'author' => '7',
        ], '본문'));

        $this->importer()->run(['author' => 42]);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame(42, (int) $rows[0]['user_id'], '--author 가 front matter 를 이겨야 한다.');
    }

    /**
     * front matter 가 없으면 건너뛴다.
     *
     * 현재 content/posts 의 30개 원고가 전부 이 경우다.
     */
    public function testSkipsFileWithoutFrontMatter(): void
    {
        $this->writeMarkdown('ep16.md', "# 제목만 있는 순수 마크다운\n\n본문입니다.");

        $status = $this->importer()->run([]);

        $this->assertSame(EXIT_SUCCESS, $status);
        $this->assertSame([], $this->rows(), 'front matter 가 없으면 저장하지 않는다.');

        $output = $this->getStreamFilterBuffer();
        $this->assertStringContainsString('건너뜀', $output);
        $this->assertStringContainsString('ep16.md', $output, '어떤 파일을 건너뛰었는지 알려 줘야 한다.');
        $this->assertStringContainsString('건너뜀 1', $output, '요약에 건너뜀 건수가 나와야 한다.');
    }

    /** title·slug·body 중 하나라도 비면 건너뛴다. */
    public function testSkipsFileWithMissingRequiredField(): void
    {
        $this->writeMarkdown('ep17.md', $this->post([
            'title' => '슬러그가 없다',
        ], '본문은 있다'));
        $this->writeMarkdown('ep18.md', $this->post([
            'slug' => 'no-title',
        ], '본문은 있다'));
        $this->writeMarkdown('ep19.md', $this->post([
            'title' => '본문이 없다',
            'slug'  => 'no-body',
        ], '   '));

        $status = $this->importer()->run([]);

        $this->assertSame(EXIT_SUCCESS, $status);
        $this->assertSame([], $this->rows(), '필수값이 빠지면 저장하지 않는다.');
        $this->assertStringContainsString('건너뜀 3', $this->getStreamFilterBuffer());
    }

    /** 정상 파일과 건너뛸 파일이 섞여 있으면 정상인 것만 들어간다. */
    public function testProcessesValidFilesAlongsideSkipped(): void
    {
        $this->writeMarkdown('ep20.md', $this->post([
            'title' => '정상',
            'slug'  => 'valid-one',
        ], '본문'));
        $this->writeMarkdown('ep21.md', "# front matter 없음\n\n본문");

        $this->importer()->run([]);

        $rows = $this->rows();
        $this->assertCount(1, $rows, '정상 파일만 들어가야 한다.');
        $this->assertSame('valid-one', $rows[0]['slug']);

        $output = $this->getStreamFilterBuffer();
        $this->assertStringContainsString('생성 1', $output);
        $this->assertStringContainsString('건너뜀 1', $output);
    }
}
