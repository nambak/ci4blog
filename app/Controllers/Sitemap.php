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
 *
 * URL 조립은 absolute_url() 헬퍼가 한다(#113 에서 승격) — RSS 피드와 규칙을
 * 공유해야 <loc> 과 <link> 가 갈라지지 않는다.
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
            ['loc' => absolute_url(''), 'lastmod' => $latest],
            ['loc' => absolute_url('posts'), 'lastmod' => $latest],
            // /about 은 변경 시각의 근거가 없다 — 지어내지 않고 비운다.
            ['loc' => absolute_url('about'), 'lastmod' => null],
        ];

        foreach ($posts as $post) {
            $entries[] = [
                'loc'     => absolute_url('posts/' . $post->slug),
                'lastmod' => $this->formatDate($post->updated_at),
            ];
        }

        foreach ($categories as $category) {
            $entries[] = [
                'loc'     => absolute_url('categories/' . $category['slug']),
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
