<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * 뷰·엔티티가 slug 를 직접 URL 에 조립하지 않아야 한다. (#127)
 *
 * 이 버그의 재발 경로는 정확히 "새 코드가 또 site_url('posts/' . $slug) 를 쓰는 것"이다.
 * 그런데 **버그 자체는 CI(리눅스)에서 재현되지 않아** 링크 URL 을 단언하는 테스트로는
 * 잡을 수 없다 — glibc 는 UTF-8 로케일에서도 0x80~0x9F 를 제어문자로 보지 않는다.
 * 그래서 값이 아니라 구조를 지킨다.
 *
 * @internal
 */
final class SlugUrlAssemblyTest extends CIUnitTestCase
{
    /**
     * 검사 대상 디렉터리.
     *
     * 엔티티를 포함하는 이유가 있다 — 접근자가 헬퍼를 우회해도 리눅스에서는
     * URL 결과가 같아, 구조 검사 말고는 잡을 방법이 없다.
     */
    private const DIRS = ['Views', 'Entities'];

    /**
     * @param callable(string): bool $isViolation
     *
     * @return list<string> "파일:라인: 내용" 형태의 위반 목록
     */
    private function violations(callable $isViolation): array
    {
        $found = [];

        foreach (self::DIRS as $dir) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(APPPATH . $dir)
            );

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);

                foreach ($lines as $i => $line) {
                    if ($isViolation($line)) {
                        $rel     = str_replace(APPPATH, 'app/', $file->getPathname());
                        $found[] = sprintf('%s:%d: %s', $rel, $i + 1, trim($line));
                    }
                }
            }
        }

        sort($found);

        return $found;
    }

    /**
     * 카테고리 URL 은 오직 category_url() 로 만든다.
     *
     * `site_url('admin/categories/' . $category->id . …)` 는 문자열이 달라
     * 오탐되지 않는다(id 기반이라 안전하기도 하다).
     */
    public function testNoDirectCategoryUrlAssembly(): void
    {
        $violations = $this->violations(
            static fn (string $line): bool => str_contains($line, "site_url('categories/")
        );

        $this->assertSame(
            [],
            $violations,
            "카테고리 URL 은 category_url() 을 써야 한다:\n" . implode("\n", $violations)
        );
    }

    /**
     * 글 slug URL 은 post_url() 또는 $post->url 로 만든다.
     *
     * id 기반 경로(`site_url('posts/' . $post->id . '/edit')`)는 slug 가 없어
     * 걸리지 않는다 — 숫자라 안전하므로 바꿀 이유가 없다.
     */
    public function testNoDirectPostSlugUrlAssembly(): void
    {
        $violations = $this->violations(
            static fn (string $line): bool => str_contains($line, 'site_url(')
                && str_contains($line, "'posts/'")
                && str_contains($line, 'slug')
        );

        $this->assertSame(
            [],
            $violations,
            "글 URL 은 \$post->url 또는 post_url() 을 써야 한다:\n" . implode("\n", $violations)
        );
    }
}
