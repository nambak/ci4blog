<?php

/**
 * 접근 권한(ACL) 헬퍼.
 *
 * "본인 또는 관리자" 판정은 글 수정/삭제·댓글 삭제 등 여러 곳(컨트롤러·뷰)에서
 * 반복되던 로직이다. 한 곳으로 모아 둔다.
 */

use CodeIgniter\Shield\Entities\User;

if (! function_exists('is_owner_or_admin')) {
    /**
     * 현재 로그인 사용자가 해당 리소스의 작성자 본인이거나 관리자(admin·superadmin)인지 판정한다.
     *
     * 비로그인이면 항상 false. $ownerId 가 null/0 이면(작성자 미상) 관리자만 true.
     *
     * superadmin 을 포함하는 이유: /admin 라우트 그룹이 이미 `group:admin,superadmin` 으로
     * 둘을 같은 권한으로 다룬다. 여기서 superadmin 을 빠뜨리면 관리 목록에서는 보이는 글의
     * 상세가 404 로 막히는 모순이 생긴다.
     *
     * @param int|string|null $ownerId 리소스 소유자의 user_id
     */
    function is_owner_or_admin($ownerId): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return (int) $ownerId === (int) $user->id || $user->inGroup('admin', 'superadmin');
    }
}
