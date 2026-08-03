<?php

namespace App\Controllers;

use App\Libraries\RssXml;
use App\Models\PostModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 구독자용 RSS 2.0 피드. (#113)
 *
 * 공개 라우트다 — 리더는 로그인하지 않는다(/health·/sitemap.xml 과 같은 이유로
 * session 그룹 밖에 둔다).
 *
 * DB 오류를 삼키지 않는 것은 의도다. 빈 200 피드는 리더에게 "글이 다 사라졌다"
 * 로 읽히지만, 500 은 그냥 재시도로 이어진다. (헬스체크가 예외를 삼키는 것과
 * 정반대다.)
 *
 * URL 은 absolute_url() 로 만든다 — 리더는 <link> 를 정본으로 저장하므로
 * index.php 가 붙은 형태가 한 번이라도 나가면 같은 글이 두 항목으로 갈라진다.
 */
class Feed extends BaseController
{
    /** 받아 간 쪽이 재사용해도 되는 시간(초). */
    public const MAX_AGE = 3600;

    /** 피드에 싣는 최근 글 수. */
    public const LIMIT = 20;

    /** <description> 에 넣을 요약 길이(자). 목록 카드(80자)보다 길게 잡는다. */
    public const EXCERPT_LIMIT = 200;

    public function index(): ResponseInterface
    {
        $posts = model(PostModel::class)->recentForFeed(self::LIMIT);
        $blog  = config('Blog');

        $items = [];

        foreach ($posts as $post) {
            $items[] = [
                'title' => $post->title,
                'link'  => absolute_url('posts/' . $post->slug),
                // 라우트가 없는 식별자 문자열이다 — RssXml 이 isPermaLink="false" 로
                // 선언한다. slug 를 쓰면 제목 수정 시 slug 가 바뀌어 같은 글이
                // 구독자에게 새 글로 다시 뜬다(git-as-CMS 라 실제로 일어난다).
                'guid' => absolute_url('posts/id/' . $post->id),
                // created_at 은 마이그레이션상 nullable 이다 — 값이 없으면 태그를
                // 뺀다(RssXml 이 생략한다). 무조건 format() 을 부르면 그 한 행 때문에
                // 문서 전체가 500 이 된다.
                'pubDate'     => $post->created_at?->format(DATE_RSS),
                'description' => $post->getExcerpt(self::EXCERPT_LIMIT),
            ];
        }

        // 채널 내용이 마지막으로 바뀐 시각 = 실린 항목들의 updated_at 최대값.
        // 정렬이 created_at 기준이라 첫 글이 최대값이라는 보장이 없다(sitemap 과
        // 다른 점 — sitemap 은 updated_at DESC 정렬이라 첫 행이 곧 최대값이다).
        // updated_at 도 nullable 이므로 null 인 항목은 최대값 계산에서 제외한다.
        $timestamps    = array_filter(array_map(static fn ($p) => $p->updated_at, $posts));
        $lastBuildDate = $timestamps === [] ? null : max($timestamps)->format(DATE_RSS);

        // 반드시 setCache() 를 쓴다 — setHeader('Cache-Control', …) 는 생성자의
        // noCache() 가 남긴 배열 값에 덧붙어 no-store 가 앞에 남는다(Sitemap 참조).
        $this->response->setCache(['public', 'max-age' => self::MAX_AGE]);

        return $this->response
            ->setContentType('application/rss+xml')
            ->setBody((new RssXml())->render([
                'title'         => $blog->title,
                'link'          => absolute_url(),
                'description'   => $blog->description,
                'feedUrl'       => absolute_url('feed'),
                'lastBuildDate' => $lastBuildDate,
            ], $items));
    }
}
