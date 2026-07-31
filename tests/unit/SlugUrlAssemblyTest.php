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
 * **이것은 정적 분석기가 아니다.** 경로를 쪼개 쓰거나(`'posts' . '/' . $slug`)
 * 변수에 담아 넘기면(`$p = 'posts/'; site_url($p . $slug)`) 빠져나간다. 정규식을
 * 넓혀도 우회는 계속 남으므로, 완전성 대신 **기존 줄을 복사해 붙이는 재발 경로**를
 * 막는 데 목적을 둔다. 그 형태가 실제로 이 저장소에서 12곳을 만든 경로였다.
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
     * 파일 전체를 정규식으로 훑어 위반 위치를 모은다.
     *
     * 줄 단위로 보면 표현이 여러 줄에 걸치거나 따옴표가 달라지는 순간 놓친다.
     * 그래서 파일 내용을 통째로 매칭하고 오프셋으로 줄 번호를 되짚는다.
     *
     * @return list<string> "파일:라인: 내용" 형태의 위반 목록
     */
    private function violations(string $pattern): array
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

                $source = (string) file_get_contents($file->getPathname());

                if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
                    continue;
                }

                $rel = str_replace(APPPATH, 'app/', $file->getPathname());

                foreach ($matches[0] as [$text, $offset]) {
                    $line     = substr_count($source, "\n", 0, $offset) + 1;
                    $found[] = sprintf('%s:%d: %s', $rel, $line, trim(preg_replace('/\s+/', ' ', $text)));
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
        // 따옴표 종류를 가리지 않고, site_url( 과 경로 사이의 공백·줄바꿈도 허용한다.
        $violations = $this->violations('/site_url\(\s*[\'"]categories\//');

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
        // site_url('posts/' … slug …) — 인자 안 어디든 slug 가 있으면 위반이다.
        // [^)]* 가 닫는 괄호 전까지만 보므로 뒤따르는 다른 호출을 삼키지 않는다.
        $violations = $this->violations('/site_url\(\s*[\'"]posts\/[\'"][^)]*slug[^)]*\)/');

        $this->assertSame(
            [],
            $violations,
            "글 URL 은 \$post->url 또는 post_url() 을 써야 한다:\n" . implode("\n", $violations)
        );
    }
}
