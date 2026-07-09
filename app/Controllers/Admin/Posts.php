<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Entities\Post;
use App\Models\CategoryModel;
use App\Models\CommentModel;
use App\Models\PostModel;

/**
 * 관리자 게시글 관리.
 *
 * 라우트 그룹의 Shield `group:admin,superadmin` 필터가 접근을 막으므로
 * 이 컨트롤러는 이미 admin/superadmin 인 요청만 처리한다.
 */
class Posts extends BaseController
{
    private const PER_PAGE = 20;

    public function index(): string
    {
        // 탭. 허용 값 밖이면 조용히 '전체'로 떨어뜨린다.
        $status = (string) ($this->request->getGet('status') ?? 'all');
        if (! in_array($status, Post::STATUSES, true)) {
            $status = 'all';
        }

        $search = trim((string) $this->request->getGet('q'));

        $model = model(PostModel::class);
        $db    = db_connect();

        // 카테고리명은 조인으로, 댓글 수는 상관 서브쿼리로 한 번에 가져온다(N+1 회피).
        // GROUP BY 대신 서브쿼리를 쓰는 이유: 조인된 categories.name 은 posts.id 에
        // 함수 종속으로 인식되지 않아 MySQL ONLY_FULL_GROUP_BY 와 부딪힌다.
        //
        // select() 안에 괄호가 있으면 CI4 가 식별자 프리픽스를 건너뛰므로(DBPrefix 미적용)
        // 테이블명을 prefixTable() 로 직접 채워 넣는다(대시보드와 동일한 함정).
        $model->select(sprintf(
            '%1$s.*, %2$s.name AS category_name,'
            . ' (SELECT COUNT(*) FROM %3$s WHERE %3$s.post_id = %1$s.id) AS comment_count',
            $db->prefixTable('posts'),
            $db->prefixTable('categories'),
            $db->prefixTable('comments')
        ))->join('categories', 'categories.id = posts.category_id', 'left');

        if ($status !== 'all') {
            $model->where('posts.status', $status);
        }

        // 관리 화면의 검색창은 "제목 검색"이다(공개 검색은 제목+본문).
        if ($search !== '') {
            $model->like('posts.title', $search);
        }

        $posts = $model
            ->orderBy('posts.created_at', 'DESC')
            ->orderBy('posts.id', 'DESC')
            ->paginate(self::PER_PAGE);

        // 페이지 링크가 현재 탭·검색어를 잃지 않도록 질의 문자열을 보존한다.
        $model->pager->only(['status', 'q']);

        return view('admin/posts/index', [
            'posts'  => $posts,
            'pager'  => $model->pager,
            'status' => $status,
            'search' => $search,
            // 탭 카운트는 검색 결과 안의 분포(탭 숫자와 보이는 행 수를 맞춘다).
            'counts' => model(PostModel::class)->statusCounts($search !== '' ? $search : null),
            // 통계 카드는 검색과 무관한 전체 기준이다.
            'totals'         => model(PostModel::class)->statusCounts(),
            'commentsLast30' => model(CommentModel::class)
                ->where('created_at >=', date('Y-m-d H:i:s', strtotime('-30 days')))
                ->countAllResults(),
            'categories' => model(CategoryModel::class)->menu(),
        ]);
    }
}
