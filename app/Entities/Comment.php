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
    protected $dates = ['created_at', 'updated_at'];

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
