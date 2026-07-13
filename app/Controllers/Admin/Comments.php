<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Entities\Comment;
use App\Models\CommentModel;
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

    public function index(): string
    {
        // 탭. 허용 값 밖이면 조용히 '전체'로 떨어뜨린다.
        $status = (string) ($this->request->getGet('status') ?? 'all');
        if (! in_array($status, Comment::STATUSES, true)) {
            $status = 'all';
        }

        $search = trim((string) $this->request->getGet('q'));

        $model = model(CommentModel::class);

        // 목록에는 최상위 댓글만 선다. 답글은 각 행 안의 미리보기로 보여 준다.
        $model->select('comments.*, users.username, users.avatar, posts.title AS post_title, posts.slug AS post_slug')
            ->join('users', 'users.id = comments.user_id', 'left')
            ->join('posts', 'posts.id = comments.post_id', 'left')
            ->where('comments.parent_id', null);

        if ($status !== 'all') {
            $model->where('comments.status', $status);
        }

        // 검색은 댓글 본문 또는 작성자명이다.
        if ($search !== '') {
            $model->groupStart()
                ->like('comments.body', $search)
                ->orLike('users.username', $search)
                ->groupEnd();
        }

        $comments = $model
            ->orderBy('comments.created_at', 'DESC')
            ->orderBy('comments.id', 'DESC')
            ->paginate(self::PER_PAGE);

        // CI4 Pager 는 현재 $_GET 전체를 페이지 링크에 옮겨 담는다. only() 는 그 범위를
        // status·q 두 키로 좁히는 것이다(관계없는 파라미터가 새어 들어가지 않도록).
        $model->pager->only(['status', 'q']);

        return view('admin/comments/index', [
            'comments' => $comments,
            'replies'  => $this->repliesFor($comments),
            'pager'    => $model->pager,
            'status'   => $status,
            'search'   => $search,
            // 탭 카운트는 검색 결과 안의 분포(탭 숫자와 보이는 행 수를 맞춘다).
            'counts' => model(CommentModel::class)->statusCounts($search !== '' ? $search : null),
            // 통계 카드는 검색과 무관한 전체 기준이고, 답글까지 포함한 총계다.
            'cards' => $this->cards(),
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

        [$status, $verb] = $action === 'hide'
            ? [Comment::STATUS_HIDDEN, '숨김 처리']
            : [Comment::STATUS_VISIBLE, '복원'];

        if (! $model->update($ids, ['status' => $status])) {
            return redirect()->back()->with('errors', $model->errors());
        }

        return redirect()->back()->with('message', "{$count}개 댓글을 {$verb}했습니다.");
    }
}
