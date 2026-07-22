<?php

namespace App\Models;

use CodeIgniter\Model;

class CommentLikeModel extends Model
{
    protected $table      = 'comment_likes';
    protected $primaryKey = 'id';

    protected $returnType    = 'object';
    protected $useTimestamps = true;
    // 좋아요는 눌리거나 지워질 뿐 수정되지 않는다 — updated_at 컬럼을 두지 않는다.
    protected $updatedField = '';

    protected $allowedFields = [
        'comment_id',
        'user_id',
    ];

    /**
     * 여러 댓글의 좋아요 수를 한 번에 센다.
     *
     * 글 상세는 목록의 모든 댓글에 카운트를 붙이므로 댓글마다 조회하면 그대로 N+1 이다.
     * CommentReportModel::pendingCountsByComment() 와 같은 형태로 맞춘다.
     *
     * @param list<int> $commentIds
     *
     * @return array<int, int> [comment_id => count] — 좋아요가 없는 댓글은 키가 없다
     */
    public function countsByComment(array $commentIds): array
    {
        if ($commentIds === []) {
            return [];
        }

        $rows = $this->select('comment_id, COUNT(*) AS cnt')
            ->whereIn('comment_id', $commentIds)
            ->groupBy('comment_id')
            ->findAll();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->comment_id] = (int) $row->cnt;
        }

        return $out;
    }

    /**
     * 이 사용자가 좋아요를 눌러 둔 댓글을 한 번에 가져온다.
     *
     * @param list<int> $commentIds
     *
     * @return array<int, true> [comment_id => true] — 뷰에서 isset() 으로 본다
     */
    public function likedByUser(array $commentIds, int $userId): array
    {
        if ($commentIds === []) {
            return [];
        }

        $rows = $this->select('comment_id')
            ->where('user_id', $userId)
            ->whereIn('comment_id', $commentIds)
            ->findAll();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->comment_id] = true;
        }

        return $out;
    }

    /** 이 사용자가 이 댓글에 좋아요를 눌러 뒀는가(토글 분기용). */
    public function hasLiked(int $commentId, int $userId): bool
    {
        return $this->where('comment_id', $commentId)
            ->where('user_id', $userId)
            ->countAllResults() > 0;
    }
}
