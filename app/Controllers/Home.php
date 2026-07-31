<?php

namespace App\Controllers;

use App\Models\CategoryModel;
use App\Models\PostModel;

class Home extends BaseController
{
    // 홈 그리드에 보여 줄 "최근 글" 개수(히어로 1건은 별도).
    private const RECENT = 6;

    /**
     * 블로그 첫 화면. 최신 글 1건을 히어로(추천 글)로, 그다음 글들을
     * "최근 글" 그리드로 보여 준다. 태그 레일은 실제 카테고리를 쓴다.
     */
    public function index(): string
    {
        // 최신순으로 (히어로 1 + 최근 RECENT)건을 한 번에 가져온다. 발행된 글만 노출한다.
        $posts    = model(PostModel::class)->published()->orderBy('created_at', 'DESC')->findAll(self::RECENT + 1);
        $featured = array_shift($posts); // 맨 앞(최신)이 히어로, 나머지가 그리드

        // 히어로 작성자명만 따로 조회(그리드 카드는 작성자를 노출하지 않음).
        // Shield 의 users 테이블에서 username 만 직접 읽는다(엔티티 의존 없이).
        $authorName   = null;
        $authorAvatar = null;
        if ($featured !== null) {
            $row          = db_connect()->table('users')->select('username, avatar')->where('id', $featured->user_id)->get()->getRow();
            $authorName   = $row->username ?? null;
            $authorAvatar = $row->avatar ?? null;
        }

        return view('home/index', [
            'featured'     => $featured,
            'authorName'   => $authorName,
            'authorAvatar' => $authorAvatar,
            'posts'        => $posts,
            'categories'   => model(CategoryModel::class)->menu(),
            // 홈은 사이트 기본값을 그대로 쓰지만 빈 배열을 **명시적으로** 넘긴다.
            // view() 는 setData 로 데이터를 누적하고 renderer 는 shared 라, 넘기지
            // 않으면 같은 프로세스에서 앞서 렌더된 페이지의 meta 가 `$meta ?? []`
            // 의 `??` 를 통과해 살아남는다(#113 실측: about → 홈 순서로 렌더하면
            // 홈의 og:title 이 '소개' 로 나왔다).
            'meta'         => [],
        ]);
    }
}