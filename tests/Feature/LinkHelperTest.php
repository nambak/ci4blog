<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * slug 링크 URL 헬퍼. (#127)
 *
 * site_url() 은 상대 경로를 parse_url 에 태우는데(SiteURI.php:128), macOS libc 의
 * iscntrl 이 UTF-8 로케일에서 0x80~0x9F 를 제어문자로 봐서 한글 slug 의 일부
 * 바이트가 '_' 로 뭉개진다. 넘기기 전에 인코딩하면 ASCII 만 남아 안전하다.
 *
 * 기대값을 rawurlencode() 로 만들지 않고 하드코딩한 것은 의도다 — 구현과 같은
 * 함수로 기대값을 만들면 그 함수가 통째로 바뀌어도 양쪽이 함께 틀려 통과한다.
 *
 * @internal
 */
final class LinkHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('link');
    }

    /** 한글 slug 는 퍼센트 인코딩된다. */
    public function testPostUrlEncodesKoreanSlug(): void
    {
        $this->assertStringEndsWith(
            'posts/%ED%95%9C%EA%B8%80-%EC%A0%9C%EB%AA%A9-%EA%B8%80',
            post_url('한글-제목-글')
        );
    }

    /**
     * 원문 한글이 그대로 새지 않아야 한다.
     *
     * 양성 단언만 있으면 "인코딩된 것도 있고 원문도 있는" 상태를 놓친다.
     */
    public function testPostUrlDoesNotLeakRawKorean(): void
    {
        $this->assertStringNotContainsString('한글-제목-글', post_url('한글-제목-글'));
    }

    public function testCategoryUrlEncodesKoreanSlug(): void
    {
        $url = category_url('한글-분류');

        $this->assertStringEndsWith('categories/%ED%95%9C%EA%B8%80-%EB%B6%84%EB%A5%98', $url);
        $this->assertStringNotContainsString('한글-분류', $url);
    }

    /**
     * ASCII slug 의 URL 은 기존과 완전히 같아야 한다.
     *
     * 이 변경으로 운영 링크가 바뀌면 안 된다 — 인코딩이 필요 없는 slug 는
     * site_url() 을 그냥 부른 것과 동일해야 한다.
     */
    public function testAsciiSlugMatchesPlainSiteUrl(): void
    {
        $this->assertSame(site_url('posts/common-layout'), post_url('common-layout'));
        $this->assertSame(site_url('categories/web'), category_url('web'));
    }

    /**
     * URL 구분자로 읽힐 문자는 인코딩된 채로 남는다.
     *
     * `?` 가 살아 있으면 slug 의 일부가 쿼리스트링으로 잘려 나간다.
     *
     * ⚠️ **슬래시는 예외다.** `rawurlencode` 가 `%2F` 로 바꿔도 `site_url()` 이
     * 다시 `/` 로 디코드한다(실측: `post_url('a/b')` → `…/posts/a/b`). 즉 이
     * 헬퍼는 경로 탈출을 막아 주지 않는다 — 막아 준다고 적으면 거짓 안심이 된다.
     * 실제로는 `slugify()` 가 `[^a-z0-9가-힣\-]` 를 전부 지우므로 slug 에
     * 슬래시가 들어올 수 없다(`app/Models/Concerns/GeneratesSlug.php:20`).
     */
    public function testQuerySeparatorInSlugStaysEncoded(): void
    {
        $this->assertStringEndsWith('posts/a%3Fb', post_url('a?b'));
    }
}
