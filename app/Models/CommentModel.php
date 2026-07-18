<?php

namespace App\Models;

use App\Entities\Comment;
use CodeIgniter\Model;

class CommentModel extends Model
{
    protected $table      = 'comments';
    protected $primaryKey = 'id';

    protected $returnType    = Comment::class;
    protected $useTimestamps = true;

    protected $allowedFields = [
        'post_id',
        'user_id',
        'parent_id',
        'body',
        'status',
    ];

    protected $validationRules = [
        'body'   => 'required',
        'status' => 'permit_empty|in_list[' . Comment::STATUS_VISIBLE . ',' . Comment::STATUS_HIDDEN . ']',
    ];

    protected $validationMessages = [
        'body' => [
            'required' => '댓글 내용을 입력해 주세요.',
        ],
        'status' => [
            'in_list' => '알 수 없는 댓글 상태입니다.',
        ],
    ];

    /**
     * 글 한 건의 공개 댓글을 스레드로 가져온다.
     *
     * 최상위 댓글만 배열로 돌려주고, 각 댓글의 replies 에 그 답글을 채운다.
     * 질의는 한 번이다 — 평면으로 가져와 PHP 에서 묶는다(N+1 회피).
     *
     * 숨김 규칙: 숨긴 댓글은 빠지고, **숨긴 부모의 답글도 함께 빠진다.**
     * 자식의 status 를 캐스케이드로 바꾸지 않고 조회 시 부모를 함께 보는 이유는,
     * 부모를 복원했을 때 원래 개별로 숨겨 두었던 답글이 딸려 살아나지 않게 하기 위해서다.
     *
     * @return Comment[]
     */
    public function forPost(int $postId): array
    {
        $rows = $this->visibleForPost($postId);

        /** @var array<int, Comment> $parents */
        $parents = [];
        /** @var array<int, Comment[]> $repliesByParent */
        $repliesByParent = [];

        foreach ($rows as $row) {
            if ($row->isReply()) {
                $repliesByParent[(int) $row->parent_id][] = $row;
            } else {
                $parents[(int) $row->id] = $row;
            }
        }

        foreach ($parents as $id => $parent) {
            // 부모가 빠진 답글(부모가 숨겨진 경우)은 애초에 $parents 에 없으므로 버려진다.
            $parent->replies = $repliesByParent[$id] ?? [];
        }

        return array_values($parents);
    }

    /**
     * 글 한 건의 공개 댓글 수(최상위 + 답글).
     *
     * 뷰에서 count() 로 세면 부모 안에 중첩된 답글을 놓치므로 모델이 총합을 돌려준다.
     */
    public function countForPost(int $postId): int
    {
        return count($this->visibleForPost($postId));
    }

    /**
     * 보이는 댓글을 평면 배열로 가져온다(최상위 + 답글 섞임, 오래된 순).
     *
     * 부모가 숨겨진 답글을 걷어내기 위해 comments 를 자기 자신에 self-join 한다.
     * 최상위 댓글은 parent 가 없으므로(LEFT JOIN → NULL) 조건을 통과한다.
     *
     * users 를 함께 조인해 작성자명을 한 번에 가져온다(N+1 회피).
     *
     * @return Comment[]
     */
    private function visibleForPost(int $postId): array
    {
        return $this
            ->select('comments.*, users.username, users.avatar')
            ->join('users', 'users.id = comments.user_id', 'left')
            ->join('comments AS parent', 'parent.id = comments.parent_id', 'left')
            ->where('comments.post_id', $postId)
            ->where('comments.status', Comment::STATUS_VISIBLE)
            ->groupStart()
                ->where('comments.parent_id', null)
                ->orWhere('parent.status', Comment::STATUS_VISIBLE)
            ->groupEnd()
            ->orderBy('comments.created_at', 'ASC')
            ->orderBy('comments.id', 'ASC')
            ->findAll();
    }

    /**
     * 댓글을 지우면 그 답글도, 답글의 답글도 재귀적으로 함께 지운다.
     *
     * MySQL 에는 parent_id 에 ON DELETE CASCADE 가 걸려 있지만, 테스트가 도는 SQLite 는
     * 컬럼 추가만으로 FK 를 걸 수 없다(테이블 재생성이 필요). 애플리케이션에서 한 번 더
     * 지워 두 환경의 동작을 같게 맞춘다.
     *
     * 설계상 답글은 1단계만 허용되지만(컨트롤러가 "답글에 답글"을 막는다), 코드가 스스로 그
     * 가정을 보장하지는 않는다. 데이터가 어떤 경로로든 2단계 이상 중첩되면, 직계 자식만 지우는
     * 방식으로는 손자 답글이 고아로 남는다. MySQL 의 자기참조 ON DELETE CASCADE 는 재귀적으로
     * 동작하므로, 더 이상 지울 자식이 없을 때까지 자식 id 를 반복해서 모아 지워야 두 환경의
     * 동작이 같아진다. 답글이 1단계뿐인 정상 데이터에서는 두 번째 회차(1회차에서 모은 답글
     * id 들 아래에 자식이 있는지 확인)가 자식을 찾지 못해 빈 배열로 즉시 끝나므로 비용은
     * 사실상 같다. MySQL 에서는 이미 CASCADE 로 사라진 행을 한 번 더 지우는 셈이라 무해하다.
     *
     * 재귀 과정에서 모은 삭제 대상 전체 id($allIds)로 comment_reports 도 함께 지운다.
     * MySQL 은 comment_reports.comment_id 의 FK CASCADE 로 이미 사라지지만, 테스트가 도는
     * SQLite 는 FK 를 걸 수 없어 정합성을 위해 애플리케이션에서 한 번 더 지운다(답글 재귀
     * 삭제와 같은 철학).
     */
    public function delete($id = null, bool $purge = false)
    {
        $ids    = array_values(array_filter(array_map('intval', (array) $id)));
        $allIds = $ids;

        while ($ids !== []) {
            $childIds = array_values(array_filter(array_map(
                'intval',
                array_column($this->builder()->whereIn('parent_id', $ids)->get()->getResultArray(), 'id')
            )));

            $this->builder()->whereIn('parent_id', $ids)->delete();

            $allIds = array_merge($allIds, $childIds);
            $ids    = $childIds;
        }

        if ($allIds !== []) {
            $this->db->table('comment_reports')->whereIn('comment_id', $allIds)->delete();
        }

        return parent::delete($id, $purge);
    }

    /**
     * 상태별 최상위 댓글 수. 관리 화면의 탭 카운트가 쓴다.
     *
     * **최상위만 센다** — 목록에 최상위만 행으로 서므로, 탭 숫자와 보이는 행 수가
     * 어긋나지 않게 하기 위해서다. (통계 카드는 답글까지 포함한 전체 기준이라 값이 다를 수 있다.)
     *
     * $search 가 주어지면 본문·작성자명으로 좁힌 결과의 분포를 돌려준다.
     *
     * @return array{visible:int, hidden:int}
     */
    public function statusCounts(?string $search = null): array
    {
        $builder = $this->db->table($this->table)
            ->select($this->table . '.status, COUNT(*) AS cnt')
            ->join('users', 'users.id = ' . $this->table . '.user_id', 'left')
            ->where($this->table . '.parent_id', null)
            ->groupBy($this->table . '.status');

        if ($search !== null && $search !== '') {
            $builder->groupStart()
                ->like($this->table . '.body', $search)
                ->orLike('users.username', $search)
                ->groupEnd();
        }

        // 0건인 상태는 GROUP BY 결과에 아예 없다. 두 상태를 0으로 깔고 덮어쓴다.
        $counts = [
            Comment::STATUS_VISIBLE => 0,
            Comment::STATUS_HIDDEN  => 0,
        ];

        foreach ($builder->get()->getResultArray() as $row) {
            $counts[$row['status']] = (int) $row['cnt'];
        }

        return $counts;
    }
}
