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

if (! function_exists('category_url')) {
    /** 카테고리로 글 목록을 거르는 URL. */
    function category_url(string $slug): string
    {
        return site_url('categories/' . rawurlencode($slug));
    }
}
