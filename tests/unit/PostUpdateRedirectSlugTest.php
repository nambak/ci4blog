<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Posts::update() 의 성공 리다이렉트가 slug 를 URL 에 직접 조립하지 않아야 한다. (#152)
 *
 * redirect()->to() 는 'http' 로 시작하지 않는 인자를 내부에서 site_url() 에 태운다
 * (RedirectResponse::to()). site_url() 은 상대 경로를 parse_url() 에 넘기는데,
 * macOS libc 의 iscntrl 이 UTF-8 로케일에서 0x80~0x9F 를 제어문자로 봐서 한글
 * slug 의 일부 바이트가 뭉개진다 — 관리자가 글을 수정하고 저장하면 그 뭉개진
 * 주소로 리다이렉트되어 상세 페이지가 404 로 응답한다.
 *
 * 뷰·엔티티는 이미 같은 버그를 겪었고 post_url() 헬퍼로 고쳤다
 * (SlugUrlAssemblyTest, #127). 그런데 그 정리는 뷰·엔티티만 훑어서, 컨트롤러의
 * redirect()->to('posts/' . $post->slug) 는 같은 문법이 아니라서 걸리지 않고
 * 남아 있었다.
 *
 * 이 버그는 리눅스(CI)에서 재현되지 않는다 — glibc 는 그 로케일에서도 제어문자로
 * 보지 않는다. 그래서 SlugUrlAssemblyTest 와 같은 이유로, 값이 아니라 구조를 지킨다.
 *
 * @internal
 */
final class PostUpdateRedirectSlugTest extends CIUnitTestCase
{
    /**
     * Posts::update() 본문만 잘라 그 안에서 위반 패턴을 찾는다.
     *
     * 파일 전체를 검사하면 다른 메서드의 무관한 코드까지 걸릴 수 있어, 문제의
     * 메서드로 범위를 좁힌다.
     */
    public function testUpdateRedirectDoesNotAssembleSlugDirectly(): void
    {
        $method = new ReflectionMethod(\App\Controllers\Posts::class, 'update');
        $lines  = (array) file((string) $method->getFileName());
        $body   = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertDoesNotMatchRegularExpression(
            '/redirect\(\)->to\(\s*[\'"]posts\/[\'"][^)]*slug[^)]*\)/',
            $body,
            "Posts::update() 의 리다이렉트는 post_url() 을 써야 한다(한글 slug 뭉개짐, #152)."
        );
    }
}
