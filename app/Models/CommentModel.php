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
     * 댓글을 지우면 그 답글도 함께 지운다.
     *
     * MySQL 에는 parent_id 에 ON DELETE CASCADE 가 걸려 있지만, 테스트가 도는 SQLite 는
     * 컬럼 추가만으로 FK 를 걸 수 없다(테이블 재생성이 필요). 애플리케이션에서 한 번 더
     * 지워 두 환경의 동작을 같게 맞춘다. MySQL 에서는 이미 사라진 행을 지우는 셈이라 무해하다.
     */
    public function delete($id = null, bool $purge = false)
    {
        $ids = array_values(array_filter(array_map('intval', (array) $id)));

        if ($ids !== []) {
            $this->builder()->whereIn('parent_id', $ids)->delete();
        }

        return parent::delete($id, $purge);
    }
}
