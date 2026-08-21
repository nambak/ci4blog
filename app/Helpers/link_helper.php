<?php

/**
 * slug 링크 URL 헬퍼. (#127)
 *
 * site_url() 은 상대 경로를 parse_url('http://dummy/' . $path) 에 태운다
 * (system/HTTP/SiteURI.php:128). PHP 는 그 안에서 제어문자를 '_' 로 치환하는데,
 * **macOS libc 의 iscntrl 은 UTF-8 로케일에서 0x80~0x9F(C1)까지 제어문자로 본다.**
 * 그래서 한글 slug 의 일부 바이트가 뭉개진다(로컬 실측: '한글-제목-글' →
 * '%ED__%EA%B8_-…'). 리눅스(CI·운영)의 glibc 는 어느 로케일에서도 그러지 않는다.
 *
 * 넘기기 전에 rawurlencode 하면 ASCII 만 남아 parse_url 이 건드릴 것이 없다.
 * site_url() 을 버리지 않는 이유는 indexPage 처리 등 CI4 동작을 유지하기
 * 위해서다 — 그래야 기존 링크와 결과가 같다.
 */

if (! function_exists('post_url')) {
    /** 글 상세 URL. */
    function post_url(string $slug): string
    {
        return site_url('posts/' . rawurlencode($slug));
    }
}

if (! function_exists('tag_url')) {
    /** 태그 목록 URL. post_url 과 같은 이유로 rawurlencode 를 거친다(#127). */
    function tag_url(string $slug): string
    {
        return site_url('tags/' . rawurlencode($slug));
    }
}

if (! function_exists('category_url')) {
    /** 카테고리로 글 목록을 거르는 URL. */
    function category_url(string $slug): string
    {
        return site_url('categories/' . rawurlencode($slug));
    }
}

if (! function_exists('canonical_url')) {
    /**
     * 이 페이지의 정본 URL. canonical 과 og:url 이 함께 쓴다. (#GSC 색인)
     *
     * 쿼리스트링은 **page 만** 살린다. 예전에는 base_url(uri_string()) 만 써서
     * 쿼리를 통째로 버렸고, 그래서 ?page=2 도 ?page=3 도 전부 /posts 를 정본이라고
     * 선언했다 — 목록 2·3페이지에만 실린 글로 가는 신호가 스스로 지워진 셈이다.
     *
     * 반대로 쿼리를 전부 살리면 더 나쁘다. ?q=아무거나 는 무한히 만들 수 있어서
     * 색인 후보가 무한해진다. 그래서 화이트리스트다.
     *
     * page=1 에 ?page=1 을 붙이지 않는 것도 의도다 — /posts 와 같은 내용이라
     * 둘 다 정본이라고 하면 우리 손으로 중복을 하나 만든다.
     *
     * uri_string() 이 이미 퍼센트 인코딩된 경로를 돌려주므로 여기서 다시 인코딩하지
     * 않는다(그러면 %ED 가 %25ED 가 된다). 라이브에서 sitemap 의 <loc> 과
     * 바이트 단위로 같은 값이 나오는 것을 확인했다.
     */
    function canonical_url(): string
    {
        // 끝 슬래시는 정본이 아니다. `/posts/` 가 자기 자신을 정본이라 선언하면
        // `/posts` 와 둘이 색인 경쟁을 하고 크롤 예산도 두 배로 먹는다. GSC 의
        // "적절한 표준 태그가 포함된 대체 페이지" 가 이런 중복에서 쌓인다.
        //
        // 다만 **루트는 예외다.** 홈의 정본은 `https://example.com/` 이고 sitemap 도
        // 그 형태로 싣고 있어서, 여기서 슬래시를 떼면 이미 색인된 홈과 어긋난다.
        $path = trim(uri_string(), '/');
        $url  = $path === '' ? base_url() : base_url($path);

        $page = (int) (service('request')->getGet('page') ?? 1);

        return $page > 1 ? $url . '?page=' . $page : $url;
    }
}

if (! function_exists('absolute_url')) {
    /**
     * 사이트 절대 URL. 경로 세그먼트를 직접 퍼센트 인코딩한다. (#113 에서 승격)
     *
     * sitemap 의 <loc>, RSS 의 <link>·<guid> 처럼 **정식 절대 URL** 이 필요한
     * 문서가 쓴다. 리더·크롤러는 이 값을 정본으로 저장하므로 index.php 가 붙은
     * 형태가 한 번이라도 나가면 같은 글이 두 항목으로 갈라진다.
     *
     * site_url() 을 쓰지 않는 이유는 위 post_url() 주석과 같다 — 상대 경로가
     * parse_url() 을 거치면서 macOS 에서 한글 바이트가 '_' 로 뭉개진다.
     * base_url() 은 경로를 넘기지 않으면 parse_url 에 태울 비ASCII 가 없어 안전하다.
     */
    function absolute_url(string $relativePath = ''): string
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', $relativePath)));

        return rtrim(base_url(), '/') . '/' . $encoded;
    }
}
