<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

/**
 * 댓글 한 건을 나타내는 도메인 객체.
 *
 * forPost() 조인으로 들어온 작성자명은 $comment->username 으로 접근한다.
 */
class Comment extends Entity
{
    /** 댓글 상태. DB 에는 이 문자열 그대로 저장된다. */
    public const STATUS_VISIBLE = 'visible';
    public const STATUS_HIDDEN  = 'hidden';

    /** 검증(in_list)과 컨트롤러가 함께 쓰는 허용 값 목록. */
    public const STATUSES = [self::STATUS_VISIBLE, self::STATUS_HIDDEN];

    protected $dates = ['created_at', 'updated_at'];

    /** 관리자가 숨긴 댓글인가. */
    public function isHidden(): bool
    {
        return ($this->attributes['status'] ?? self::STATUS_VISIBLE) === self::STATUS_HIDDEN;
    }

    /** 다른 댓글에 달린 답글인가(최상위가 아닌가). */
    public function isReply(): bool
    {
        return ($this->attributes['parent_id'] ?? null) !== null;
    }

    /**
     * 작성자명. 작성자 계정이 없으면(탈퇴 등) 대체 문구를 돌려준다.
     */
    public function getAuthorName(): string
    {
        $username = $this->attributes['username'] ?? null;

        return $username !== null && $username !== ''
            ? (string) $username
            : '알 수 없음';
    }

    /**
     * 작성자 아바타 경로. 없으면 null(뷰가 이니셜 폴백).
     */
    public function getAuthorAvatar(): ?string
    {
        $avatar = $this->attributes['avatar'] ?? null;

        return $avatar !== null && $avatar !== '' ? (string) $avatar : null;
    }
}
