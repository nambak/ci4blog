<?php

namespace App\Controllers;

use App\Libraries\SitemapXml;
use App\Models\CategoryModel;
use App\Models\PostModel;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;

/**
 * 검색엔진용 sitemap.xml. (#124)
 *
 * 공개 라우트다 — 크롤러는 로그인하지 않는다(/health 와 같은 이유로 session
 * 그룹 밖에 둔다).
 *
 * DB 오류를 삼키지 않는 것은 의도다. 빈 200 sitemap 은 크롤러에게 "URL 이
 * 사라졌다" 로 읽혀 색인이 빠질 수 있지만, 500 은 그냥 재시도로 이어진다.
 * (헬스체크가 예외를 삼키는 것과 정반대다.)
 */
class Sitemap extends BaseController
{
    /** 받아 간 쪽이 재사용해도 되는 시간(초). */
    public const MAX_AGE = 3600;

    public function index(): ResponseInterface
    {
        $posts      = model(PostModel::class)->publishedForSitemap();
        $categories = model(CategoryModel::class)->visibleWithPublishedPosts();

        // 목록이 updated_at DESC 라 첫 글이 곧 사이트 전체의 최신 시각이다.
        // 글이 하나도 없으면 근거가 없으므로 홈·목록의 lastmod 도 비운다.
        $latest = isset($posts[0]) ? $this->formatDate($posts[0]->updated_at) : null;

        $entries = [
            ['loc' => $this->url(''), 'lastmod' => $latest],
            ['loc' => $this->url('posts'), 'lastmod' => $latest],
            // /about 은 변경 시각의 근거가 없다 — 지어내지 않고 비운다.
            ['loc' => $this->url('about'), 'lastmod' => null],
        ];

        foreach ($posts as $post) {
            $entries[] = [
                'loc'     => $this->url('posts/' . $post->slug),
                'lastmod' => $this->formatDate($post->updated_at),
            ];
        }

        foreach ($categories as $category) {
            $entries[] = [
                'loc'     => $this->url('categories/' . $category['slug']),
                'lastmod' => $this->formatDate($category['last_updated']),
            ];
        }

        // 반드시 setCache() 를 쓴다 — setHeader('Cache-Control', …) 는 생성자의
        // noCache() 가 남긴 배열 값에 덧붙어 no-store 가 앞에 남는다(Uploads 참조).
        $this->response->setCache(['public', 'max-age' => self::MAX_AGE]);

        return $this->response
            ->setContentType('application/xml')
            ->setBody((new SitemapXml())->render($entries));
    }

    /**
     * 사이트 절대 URL. 경로 세그먼트를 직접 퍼센트 인코딩한다.
     *
     * site_url() 을 쓰지 않는 이유가 있다 — SiteURI 생성자가 상대 경로를
     * parse_url() 에 통과시키는데(SiteURI.php:128), PHP 의 parse_url 은 제어문자를
     * '_' 로 치환하고 **macOS 의 iscntrl 은 0x80~0x9F 바이트까지 제어문자로 본다**.
     * 그래서 한글 slug 의 일부 바이트가 '_' 로 뭉개진다(로컬 실측:
     * '한글-제목-글' → '%ED__%EA%B8_-...'). 리눅스(CI·운영)에서는 정상이라
     * 라이브 링크는 멀쩡했지만, sitemap 의 <loc> 은 정확한 절대 URL 이어야 하는
     * 문서라 플랫폼에 따라 결과가 달라지는 것을 허용할 수 없다.
     *
     * base_url() 은 경로를 넘기지 않으면 parse_url 에 태울 비ASCII 가 없어 안전하다.
     */
    private function url(string $relativePath): string
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', $relativePath)));

        return rtrim(base_url(), '/') . '/' . $encoded;
    }

    /**
     * lastmod 용 W3C Datetime 문자열. 값이 없으면 null 을 돌려 태그를 지운다.
     *
     * 두 가지 타입이 들어온다 — 글은 엔티티라 Time 이고, 카테고리 집계는
     * 빌더 raw 결과라 문자열이다.
     */
    private function formatDate(Time|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ($value instanceof Time ? $value : Time::parse($value))->format(DATE_ATOM);
    }
}
