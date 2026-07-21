<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Entities\Post;
use App\Models\CategoryModel;
use App\Models\CommentModel;
use App\Models\PostModel;
use CodeIgniter\HTTP\RedirectResponse;

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

        // CI4 Pager는 기본적으로 현재 $_GET 전체를 페이지 링크에 그대로 옮겨 담는다.
        // 즉 이 only() 호출이 "보존을 켜는" 것이 아니라, 이미 보존되는 범위를
        // status·q 두 키로 "좁히는" 것이다 — 관계없는 질의 파라미터가 페이지 링크에
        // 새어 들어가지 않도록 막는 목적.
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
            // 이 화면에서는 아직 쓰지 않지만, 바로 다음 작업(카테고리 일괄 이동 <select>)이
            // 이 카테고리 목록을 그대로 재사용한다. 죽은 코드가 아니라 다음 작업의 준비물이니
            // 지우지 말 것.
            // 숨김 카테고리로도 옮길 수 있어야 하므로 forForm()(숨김 포함)을 쓴다(#67).
            'categories' => model(CategoryModel::class)->forForm(),
        ]);
    }

    /**
     * 일괄 작업. action 화이트리스트 밖이면 아무것도 하지 않는다.
     *
     * 작성자 확인은 하지 않는다 — 라우트 그룹의 admin 필터가 이미 전권을 전제한다.
     */
    public function bulk(): RedirectResponse
    {
        $action = (string) $this->request->getPost('action');

        if (! in_array($action, ['publish', 'draft', 'private', 'move', 'delete'], true)) {
            return redirect()->back()->with('errors', ['알 수 없는 작업입니다.']);
        }

        // 체크박스는 문자열로 온다. 정수로 바꾸고 0 이하를 걸러낸다.
        $ids = array_values(array_filter(
            array_map('intval', (array) ($this->request->getPost('ids') ?? [])),
            static fn (int $id): bool => $id > 0
        ));

        if ($ids === []) {
            return redirect()->back()->with('errors', ['선택된 글이 없습니다.']);
        }

        $model = model(PostModel::class);
        $count = count($ids);

        if ($action === 'delete') {
            // comments 는 post_id 에 ON DELETE CASCADE 가 걸려 있어 함께 지워진다.
            $model->delete($ids);

            return redirect()->back()->with('message', "{$count}개 글을 삭제했습니다.");
        }

        if ($action === 'move') {
            $raw = trim((string) $this->request->getPost('category_id'));

            // 캐스팅 전에 문자열을 검증한다. (int) 를 먼저 하면 "abc"→0, "5x"→5 처럼
            // 조작된 값이 새므로(UI select 는 이런 값을 만들지 않는다), 양의 정수만 받는다.
            // 빈 값은 미분류(NULL), 실존 여부는 모델의 is_not_unique 규칙이 막는다.
            if ($raw === '') {
                $categoryId = null;
            } elseif (ctype_digit($raw) && (int) $raw > 0) {
                $categoryId = (int) $raw;
            } else {
                return redirect()->back()->with('errors', ['올바르지 않은 카테고리입니다.']);
            }

            if (! $model->update($ids, ['category_id' => $categoryId])) {
                return redirect()->back()->with('errors', $model->errors());
            }

            return redirect()->back()->with('message', "{$count}개 글의 카테고리를 옮겼습니다.");
        }

        // 남은 셋은 상태 변경이다. 저장할 값과 플래시 메시지의 동사를 함께 꺼낸다.
        $statusMap = [
            'publish' => [Post::STATUS_PUBLISHED, '발행'],
            'draft'   => [Post::STATUS_DRAFT, '임시저장으로 변경'],
            'private' => [Post::STATUS_PRIVATE, '비공개로 변경'],
        ];
        [$status, $verb] = $statusMap[$action];

        if (! $model->update($ids, ['status' => $status])) {
            return redirect()->back()->with('errors', $model->errors());
        }

        return redirect()->back()->with('message', "{$count}개 글을 {$verb}했습니다.");
    }
}
