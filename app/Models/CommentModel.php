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
        'body',
    ];

    protected $validationRules = [
        'body' => 'required',
    ];

    protected $validationMessages = [
        'body' => [
            'required' => '댓글 내용을 입력해 주세요.',
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
            ->select('comments.*, users.username')
            ->join('users', 'users.id = comments.user_id', 'left')
            ->where('comments.post_id', $postId)
            ->orderBy('comments.created_at', 'ASC')
            ->findAll();
    }
}
