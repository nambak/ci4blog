<?php

namespace App\Models;

use CodeIgniter\Model;

class CommentReportModel extends Model
{
    protected $table      = 'comment_reports';
    protected $primaryKey = 'id';

    protected $returnType    = 'object';
    protected $useTimestamps = true;

    protected $allowedFields = [
        'comment_id',
        'reporter_user_id',
        'reason',
        'status',
    ];

    public const STATUS_PENDING  = 'pending';
    public const STATUS_REVIEWED = 'reviewed';

    /** 검증(in_list)과 컨트롤러가 함께 쓰는 상태 목록. */
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_REVIEWED];

    /**
     * 신고 사유 카테고리. 키는 DB 저장값, 값은 표시 문구.
     * 폼 드롭다운과 아래 validationRules 의 in_list 가 이 목록을 공유한다
     * (키를 바꾸면 in_list 문자열도 같이 바꿀 것 — 상수 초기화에서는 함수 호출을 못 써 하드코딩한다).
     */
    public const REASONS = [
        'spam'     => '스팸/광고',
        'abuse'    => '욕설·혐오',
        'offtopic' => '주제와 무관',
        'etc'      => '기타',
    ];

    protected $validationRules = [
        'comment_id'       => 'required|is_natural_no_zero',
        'reporter_user_id' => 'required|is_natural_no_zero',
        'reason'           => 'required|in_list[spam,abuse,offtopic,etc]',
        'status'           => 'permit_empty|in_list[pending,reviewed]',
    ];

    protected $validationMessages = [
        'reason' => [
            'required' => '신고 사유를 선택해 주세요.',
            'in_list'  => '알 수 없는 신고 사유입니다.',
        ],
    ];

    /** 이 사용자가 이 댓글을 이미 신고했는가(중복 신고 판정). */
    public function hasReported(int $commentId, int $userId): bool
    {
        return $this->where('comment_id', $commentId)
            ->where('reporter_user_id', $userId)
            ->countAllResults() > 0;
    }

    /**
     * 주어진 댓글들의 pending 신고 수를 [comment_id => count] 로 돌려준다(N+1 회피).
     *
     * @param int[] $commentIds
     *
     * @return array<int, int>
     */
    public function pendingCountsByComment(array $commentIds): array
    {
        if ($commentIds === []) {
            return [];
        }

        $rows = $this->select('comment_id, COUNT(*) AS cnt')
            ->where('status', self::STATUS_PENDING)
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
     * pending 신고가 하나라도 있는 댓글 id 목록.
     *
     * @return int[]
     */
    public function pendingReportedCommentIds(): array
    {
        $rows = $this->select('comment_id')
            ->where('status', self::STATUS_PENDING)
            ->groupBy('comment_id')
            ->findAll();

        return array_map(static fn ($r): int => (int) $r->comment_id, $rows);
    }

    /**
     * 선택 댓글들의 pending 신고를 reviewed 로 표시한다.
     *
     * @param int[] $commentIds
     */
    public function markReviewedForComments(array $commentIds): void
    {
        if ($commentIds === []) {
            return;
        }

        $this->db->table('comment_reports')
            ->whereIn('comment_id', $commentIds)
            ->where('status', self::STATUS_PENDING)
            ->update(['status' => self::STATUS_REVIEWED]);
    }
}
