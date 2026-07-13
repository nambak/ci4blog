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
        'status' => 'permit_empty|in_list[visible,hidden]',
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
     * 특정 글의 댓글을 작성자명과 함께 오래된 순으로 가져온다.
     *
     * users 를 한 번에 조인해 N+1 을 피한다. (댓글마다 작성자를 따로 조회하지 않음)
     *
     * @return Comment[]
     */
    public function forPost(int $postId): array
    {
        return $this
            ->select('comments.*, users.username, users.avatar')
            ->join('users', 'users.id = comments.user_id', 'left')
            ->where('comments.post_id', $postId)
            ->orderBy('comments.created_at', 'ASC')
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
     * 동작이 같아진다. 답글이 1단계뿐인 정상 데이터에서는 루프가 한 번 돌고 끝나 비용은
     * 사실상 같다. MySQL 에서는 이미 CASCADE 로 사라진 행을 한 번 더 지우는 셈이라 무해하다.
     */
    public function delete($id = null, bool $purge = false)
    {
        $ids = array_values(array_filter(array_map('intval', (array) $id)));

        while ($ids !== []) {
            $childIds = array_column($this->builder()->whereIn('parent_id', $ids)->get()->getResultArray(), 'id');

            $this->builder()->whereIn('parent_id', $ids)->delete();

            $ids = array_values(array_filter(array_map('intval', $childIds)));
        }

        return parent::delete($id, $purge);
    }
}
