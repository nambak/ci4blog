<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * content/posts/*.md (front matter + 마크다운 본문)를 읽어
 * posts 테이블에 slug 기준으로 멱등 upsert 하는 발행 커맨드.
 *
 * 사용 예:
 *   php spark posts:import
 *   php spark posts:import --only=ep07
 *   php spark posts:import --dry-run
 *   php spark posts:import --author=1
 *
 * 설계 메모(docs/auto-publish.md):
 *  - slug 이 upsert 의 기준 키다. 같은 파일을 몇 번 import 해도 결과가 같다.
 *  - body 는 마크다운 "원문"을 그대로 저장한다(표시용 변환은 ep26 Entity 접근자가 담당).
 *  - PostModel 은 저장 시 제목으로 slug 를 자동 생성하므로, front matter 의 slug 를
 *    보존하기 위해 모델이 아니라 쿼리 빌더로 직접 쓴다(PostSeeder 와 동일한 방식).
 *  - 현재 posts 스키마에 있는 컬럼(title·slug·body·user_id·created_at·updated_at)만 채운다.
 *    category·tags·published_at 등 front matter 의 나머지 필드는 해당 컬럼이 생기는
 *    이후 회차에서 매핑을 확장한다.
 */
class PostsImport extends BaseCommand
{
    protected $group       = 'Blog';
    protected $name        = 'posts:import';
    protected $description = 'content/posts/*.md 를 posts 테이블에 slug 기준으로 업서트한다.';
    protected $usage       = 'posts:import [--only epNN] [--dry-run] [--author ID]';
    protected $options     = [
        '--only'    => '특정 회차만 import (예: --only ep07).',
        '--dry-run' => 'DB 를 건드리지 않고 무엇이 반영될지만 보여 준다.',
        '--author'  => '모든 글의 작성자(user_id)를 이 값으로 지정한다.',
    ];

    /** content/posts 디렉터리 경로 */
    private string $contentDir = ROOTPATH . 'content/posts';

    public function run(array $params)
    {
        $only   = $params['only']   ?? CLI::getOption('only');
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run');
        $author = $params['author'] ?? CLI::getOption('author');
        $author = ($author === null || $author === '') ? null : (int) $author;

        if (! is_dir($this->contentDir)) {
            CLI::error("content/posts 디렉터리가 없습니다: {$this->contentDir}");

            return EXIT_ERROR;
        }

        $pattern = $this->contentDir . '/' . ($only ? $only . '.md' : '*.md');
        $files   = glob($pattern) ?: [];

        if ($files === []) {
            CLI::write('대상 파일이 없습니다: ' . $pattern, 'yellow');

            return EXIT_SUCCESS;
        }

        if ($dryRun) {
            CLI::write('[dry-run] 실제로 DB 에 쓰지 않습니다.', 'yellow');
        }

        $db      = $dryRun ? null : Database::connect();
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($files as $file) {
            $name = basename($file);
            [$fm, $body] = $this->parse((string) file_get_contents($file));

            $title = trim((string) ($fm['title'] ?? ''));
            $slug  = trim((string) ($fm['slug'] ?? ''));
            $body  = trim($body);

            if ($title === '' || $slug === '' || $body === '') {
                CLI::write("  건너뜀: {$name} (title·slug·body 중 빠진 값이 있음)", 'red');
                $skipped++;
                continue;
            }

            $publishedAt = trim((string) ($fm['published_at'] ?? '')) ?: date('Y-m-d H:i:s');

            // 작성자: --author 옵션 > front matter author > null
            $userId = $author ?? (isset($fm['author']) ? (int) $fm['author'] : null);

            if ($dryRun) {
                CLI::write("  반영 예정: {$name} → slug={$slug}, title=\"{$title}\"");
                continue;
            }

            $builder  = $db->table('posts');
            $existing = $builder->where('slug', $slug)->get()->getRowArray();

            if ($existing !== null) {
                $db->table('posts')->where('slug', $slug)->update([
                    'title'      => $title,
                    'body'       => $body,
                    'user_id'    => $userId,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                CLI::write("  갱신: {$name} (slug={$slug})", 'cyan');
                $updated++;
            } else {
                $db->table('posts')->insert([
                    'title'      => $title,
                    'slug'       => $slug,
                    'body'       => $body,
                    'user_id'    => $userId,
                    'created_at' => $publishedAt,
                    'updated_at' => $publishedAt,
                ]);
                CLI::write("  생성: {$name} (slug={$slug})", 'green');
                $created++;
            }
        }

        CLI::newLine();
        CLI::write(sprintf('완료 — 생성 %d · 갱신 %d · 건너뜀 %d', $created, $updated, $skipped), 'green');

        return EXIT_SUCCESS;
    }

    /**
     * front matter(상단 --- 블록) 와 본문을 분리한다.
     *
     * @return array{0: array<string,mixed>, 1: string} [front matter 배열, 본문]
     */
    private function parse(string $raw): array
    {
        // BOM 제거 후 선두 공백 정리
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        if (! preg_match('/^---\s*\R(.*?)\R---\s*\R?(.*)$/s', $raw, $m)) {
            // front matter 가 없으면 전체를 본문으로 본다.
            return [[], $raw];
        }

        return [$this->parseYamlish($m[1]), $m[2]];
    }

    /**
     * 아주 단순한 YAML 유사 파서.
     * 이 파이프라인이 쓰는 형태(스칼라 key: value, 인용부호, 인라인 배열)만 처리한다.
     *
     * @return array<string,mixed>
     */
    private function parseYamlish(string $block): array
    {
        $out = [];

        foreach (preg_split('/\R/', $block) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));

            // 인라인 배열: ["a", "b"]  → 문자열 배열
            if (str_starts_with($val, '[') && str_ends_with($val, ']')) {
                $inner = trim(substr($val, 1, -1));
                $items = $inner === '' ? [] : array_map(
                    static fn ($s) => trim(trim($s), "\"'"),
                    explode(',', $inner)
                );
                $out[$key] = $items;
                continue;
            }

            // 양끝 인용부호 제거
            $val = trim($val, "\"'");

            $out[$key] = $val;
        }

        return $out;
    }
}
