<?php

namespace App\Models;

use CodeIgniter\Model;

class PostLikeModel extends Model
{
    protected $table      = 'post_likes';
    protected $primaryKey = 'id';

    protected $returnType    = 'object';
    protected $useTimestamps = true;
    // 좋아요는 눌리거나 지워질 뿐 수정되지 않는다 — updated_at 컬럼을 두지 않는다.
    protected $updatedField = '';

    protected $allowedFields = [
        'post_id',
        'user_id',
    ];

    /** 이 글의 좋아요 수. */
    public function countForPost(int $postId): int
    {
        return $this->where('post_id', $postId)->countAllResults();
    }

    /** 이 사용자가 이 글에 좋아요를 눌러 뒀는가. */
    public function hasLiked(int $postId, int $userId): bool
    {
        return $this->where('post_id', $postId)
            ->where('user_id', $userId)
            ->countAllResults() > 0;
    }
}
