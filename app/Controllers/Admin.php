<?php

namespace App\Controllers;

/**
 * 관리자 대시보드.
 *
 * 라우트 그룹에 걸린 Shield `group:admin,superadmin` 필터가 접근을 막으므로
 * 이 컨트롤러는 이미 admin/superadmin임이 보장된 요청만 처리한다.
 */
class Admin extends BaseController
{
    public function index(): string
    {
        $posts      = model(\App\Models\PostModel::class);
        $comments   = model(\App\Models\CommentModel::class);
        $categories = model(\App\Models\CategoryModel::class);

        $stats = [
            'posts'          => $posts->countAllResults(false),
            'comments'       => $comments->countAllResults(),
            'categories'     => $categories->countAllResults(),
            'postsThisMonth' => $posts->where('created_at >=', date('Y-m-01 00:00:00'))->countAllResults(),
        ];

        $recentPosts = model(\App\Models\PostModel::class)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->findAll(5);

        // 최근 댓글: 대상 글 제목/슬러그를 함께(N+1 회피). 조인 컬럼은 엔티티 속성으로 붙는다.
        $recentComments = model(\App\Models\CommentModel::class)
            ->select('comments.*, posts.title AS post_title, posts.slug AS post_slug')
            ->join('posts', 'posts.id = comments.post_id', 'left')
            ->orderBy('comments.created_at', 'DESC')
            ->orderBy('comments.id', 'DESC')
            ->findAll(5);

        // 카테고리별 글 수. category_id가 NULL이면 '미분류'로. MySQL ONLY_FULL_GROUP_BY
        // 대비로 category_id와 name을 함께 group by 한다.
        // select() 안에 괄호(함수 호출)가 있으면 CI4가 식별자 프리픽스를 건너뛰므로
        // (DBPrefix 미적용) 테이블명을 prefixTable()로 직접 채워 넣는다.
        // NOTE: 이 우회는 CI4의 BaseConnection::protectIdentifiers()가 괄호를 만나면
        //       원문을 그대로 둔다는 동작에 의존한다. CI4 업그레이드 시 재검증할 것.
        $db = db_connect();

        $categoryDist = model(\App\Models\PostModel::class)
            ->select(sprintf(
                "COALESCE(%s.name, '미분류') AS name, COUNT(%s.id) AS cnt",
                $db->prefixTable('categories'),
                $db->prefixTable('posts')
            ))
            ->join('categories', 'categories.id = posts.category_id', 'left')
            ->groupBy('posts.category_id, categories.name')
            ->orderBy('cnt', 'DESC')
            ->findAll();

        return view('admin/dashboard', [
            'stats'          => $stats,
            'recentPosts'    => $recentPosts,
            'recentComments' => $recentComments,
            'categoryDist'   => $categoryDist,
        ]);
    }
}
