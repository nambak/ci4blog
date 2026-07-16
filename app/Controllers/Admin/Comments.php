<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Entities\Comment;
use App\Models\CommentModel;
use App\Models\CommentReportModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * 관리자 댓글 관리.
 *
 * 라우트 그룹의 Shield `group:admin,superadmin` 필터가 접근을 막으므로
 * 이 컨트롤러는 이미 admin/superadmin 인 요청만 처리한다(Admin\Posts 와 같은 전제).
 */
class Comments extends BaseController
{
    private const PER_PAGE = 20;

    /**
     * 정렬 화이트리스트. 키는 ?sort= 값, 값은 [컬럼, 방향]이다.
     * 화이트리스트로 두는 이유는 ?sort= 를 orderBy 에 그대로 넘기지 않기 위해서다
     * (임의 컬럼·SQL 조각 주입 차단). 목록의 기본은 첫 항목('newest')이다.
     */
    private const SORTS = [
        'newest' => ['comments.created_at', 'DESC'],
        'oldest' => ['comments.created_at', 'ASC'],
    ];

    public function index(): string
    {
        // 탭. visible·hidden·reported 밖이면 조용히 '전체'로 떨어뜨린다.
        $status = (string) ($this->request->getGet('status') ?? 'all');
        if (! in_array($status, [Comment::STATUS_VISIBLE, Comment::STATUS_HIDDEN, 'reported'], true)) {
            $status = 'all';
        }

        // 정렬. 화이트리스트 밖(오타·위조)이면 조용히 기본값 'newest'로 떨어뜨린다.
        $sort = (string) ($this->request->getGet('sort') ?? 'newest');
        if (! isset(self::SORTS[$sort])) {
            $sort = 'newest';
        }
        [$sortColumn, $sortDir] = self::SORTS[$sort];

        $search = trim((string) $this->request->getGet('q'));

        $model = model(CommentModel::class);

        // 목록에는 최상위 댓글만 선다. 답글은 각 행 안의 미리보기로 보여 준다.
        $model->select('comments.*, users.username, users.avatar, posts.title AS post_title, posts.slug AS post_slug')
            ->join('users', 'users.id = comments.user_id', 'left')
            ->join('posts', 'posts.id = comments.post_id', 'left')
            ->where('comments.parent_id', null);

        $reports     = model(CommentReportModel::class);
        $reportedIds = $reports->pendingReportedCommentIds();

        if ($status === 'reported') {
            // 신고 탭: pending 신고가 있는 visible 최상위 댓글만.
            // 빈 배열이면 whereIn 이 유효한 SQL 이 되도록 매칭 안 되는 [0] 을 넣는다.
            $model->where('comments.status', Comment::STATUS_VISIBLE)
                ->whereIn('comments.id', $reportedIds !== [] ? $reportedIds : [0]);
        } elseif ($status !== 'all') {
            $model->where('comments.status', $status);
        }

        // 검색은 댓글 본문 또는 작성자명이다.
        if ($search !== '') {
            $model->groupStart()
                ->like('comments.body', $search)
                ->orLike('users.username', $search)
                ->groupEnd();
        }

        // 2차 정렬은 created_at 동률일 때 순서를 확정하려는 것이라, 1차 정렬 방향을 따라간다.
        $comments = $model
            ->orderBy($sortColumn, $sortDir)
            ->orderBy('comments.id', $sortDir)
            ->paginate(self::PER_PAGE);

        // CI4 Pager 는 현재 $_GET 전체를 페이지 링크에 옮겨 담는다. only() 는 그 범위를
        // status·q·sort 세 키로 좁히는 것이다(관계없는 파라미터가 새어 들어가지 않도록).
        $model->pager->only(['status', 'q', 'sort']);

        // 뱃지: 이 페이지 댓글들의 pending 신고 수(N+1 회피).
        $commentIds   = array_map(static fn ($c): int => (int) $c->id, $comments);
        $reportCounts = $reports->pendingCountsByComment($commentIds);

        // 신고 탭 카운트: 검색 범위 안에서 pending 신고가 있는 visible 최상위 댓글 수.
        $reportedCount = $this->reportedCount($reportedIds, $search);

        return view('admin/comments/index', [
            'comments' => $comments,
            'replies'  => $this->repliesFor($comments),
            'pager'    => $model->pager,
            'status'   => $status,
            'sort'     => $sort,
            'search'   => $search,
            // 탭 카운트는 검색 결과 안의 분포(탭 숫자와 보이는 행 수를 맞춘다).
            'counts' => model(CommentModel::class)->statusCounts($search !== '' ? $search : null),
            // 통계 카드는 검색과 무관한 전체 기준이고, 답글까지 포함한 총계다.
            'cards' => $this->cards(),
            'reportCounts'  => $reportCounts,
            'reportedCount' => $reportedCount,
        ]);
    }

    /**
     * 이 페이지에 실린 댓글들의 답글을 한 번에 가져와 부모 id 로 묶는다(N+1 회피).
     *
     * @param Comment[] $comments
     *
     * @return array<int, Comment[]>
     */
    private function repliesFor(array $comments): array
    {
        if ($comments === []) {
            return [];
        }

        $parentIds = array_map(static fn (Comment $c): int => (int) $c->id, $comments);

        $rows = model(CommentModel::class)
            ->select('comments.*, users.username')
            ->join('users', 'users.id = comments.user_id', 'left')
            ->whereIn('comments.parent_id', $parentIds)
            ->orderBy('comments.created_at', 'ASC')
            ->findAll();

        $byParent = [];

        foreach ($rows as $row) {
            $byParent[(int) $row->parent_id][] = $row;
        }

        return $byParent;
    }

    /**
     * 통계 카드 4장. 전부 검색과 무관한 전체 기준이며 답글도 포함한다.
     *
     * @return array{week:int, total:int, month:int, hidden:int}
     */
    private function cards(): array
    {
        return [
            'week' => model(CommentModel::class)
                ->where('created_at >=', date('Y-m-d H:i:s', strtotime('-7 days')))
                ->countAllResults(),
            'total'  => model(CommentModel::class)->countAllResults(),
            'month'  => model(CommentModel::class)
                ->where('created_at >=', date('Y-m-01 00:00:00'))
                ->countAllResults(),
            'hidden' => model(CommentModel::class)
                ->where('status', Comment::STATUS_HIDDEN)
                ->countAllResults(),
        ];
    }

    /**
     * 신고 탭 카운트. pending 신고가 있는 visible 최상위 댓글 수(검색 범위 반영).
     *
     * @param int[] $reportedIds pending 신고가 있는 댓글 id 들
     */
    private function reportedCount(array $reportedIds, string $search): int
    {
        if ($reportedIds === []) {
            return 0;
        }

        $builder = model(CommentModel::class)
            ->join('users', 'users.id = comments.user_id', 'left')
            ->where('comments.parent_id', null)
            ->where('comments.status', Comment::STATUS_VISIBLE)
            ->whereIn('comments.id', $reportedIds);

        if ($search !== '') {
            $builder->groupStart()
                ->like('comments.body', $search)
                ->orLike('users.username', $search)
                ->groupEnd();
        }

        return $builder->countAllResults();
    }

    /**
     * 일괄 작업. action 화이트리스트 밖이면 아무것도 하지 않는다.
     *
     * 작성자 확인은 하지 않는다 — 라우트 그룹의 admin 필터가 이미 전권을 전제한다
     * (Admin\Posts::bulk() 와 같은 판단).
     */
    public function bulk(): RedirectResponse
    {
        $action = (string) $this->request->getPost('action');

        if (! in_array($action, ['hide', 'restore', 'delete'], true)) {
            return redirect()->back()->with('errors', ['알 수 없는 작업입니다.']);
        }

        // 체크박스는 문자열로 온다. 정수로 바꾸고 0 이하를 걸러낸다.
        $ids = array_values(array_filter(
            array_map('intval', (array) ($this->request->getPost('ids') ?? [])),
            static fn (int $id): bool => $id > 0
        ));

        if ($ids === []) {
            return redirect()->back()->with('errors', ['선택된 댓글이 없습니다.']);
        }

        $model = model(CommentModel::class);
        $count = count($ids);

        if ($action === 'delete') {
            // CommentModel::delete() 가 답글까지 함께 지운다.
            $model->delete($ids);

            return redirect()->back()->with('message', "{$count}개 댓글을 삭제했습니다.");
        }

        // 남은 둘은 상태 변경이다. hide·restore 를 각각 명시적으로 매핑해,
        // 화이트리스트가 뚫려도 알 수 없는 action 이 조용히 '복원'으로 흐르지 않게 한다
        // (Admin\Posts::bulk() 의 $statusMap 패턴과 동일).
        $statusMap = [
            'hide'    => [Comment::STATUS_HIDDEN, '숨김 처리'],
            'restore' => [Comment::STATUS_VISIBLE, '복원'],
        ];
        [$status, $verb] = $statusMap[$action];

        if (! $model->update($ids, ['status' => $status])) {
            return redirect()->back()->with('errors', $model->errors());
        }

        return redirect()->back()->with('message', "{$count}개 댓글을 {$verb}했습니다.");
    }

    /**
     * 부모 댓글에 관리자 답글을 단다.
     *
     * post_id 는 **부모에서 가져온다** — 요청에서 받으면 위조할 수 있다.
     * 답글에는 답글을 달 수 없고(1단계 제한), 숨긴 댓글에도 달 수 없다(의미가 없다).
     */
    public function reply(int $commentId): RedirectResponse
    {
        $model  = model(CommentModel::class);
        $parent = $model->find($commentId);

        if ($parent === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        // 1단계 제한: 답글에는 답글을 달 수 없다.
        if ($parent->isReply()) {
            throw PageNotFoundException::forPageNotFound();
        }

        // 숨긴 댓글에 답글을 다는 것은 의미가 없다(공개 화면에 함께 안 보인다).
        if ($parent->isHidden()) {
            throw PageNotFoundException::forPageNotFound();
        }

        $data = [
            'post_id'   => (int) $parent->post_id,
            'user_id'   => auth()->id(),
            'parent_id' => $commentId,
            'body'      => $this->request->getPost('body'),
        ];

        if (! $model->insert($data)) {
            return redirect()->back()->withInput()->with('errors', $model->errors());
        }

        return redirect()->back()->with('message', '답글을 남겼습니다.');
    }
}
