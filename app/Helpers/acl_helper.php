<?php

/**
 * 접근 권한(ACL) 헬퍼.
 *
 * "본인 또는 관리자" 판정은 글 수정/삭제·댓글 삭제 등 여러 곳(컨트롤러·뷰)에서
 * 반복되던 로직이다. 한 곳으로 모아 둔다.
 */

use App\Entities\Post;
use App\Models\CategoryModel;
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

if (! function_exists('post_viewable')) {
    /**
     * 현재 사용자가 이 글을 공개 화면에서 볼 수 있는가.
     *
     * 글 상세뿐 아니라 그 글에 딸린 쓰기 동작(댓글 작성·신고·댓글 좋아요)이 모두
     * 같은 판정을 써야 한다. 상세가 404 인 글에 부수 동작만 열려 있으면 응답 차이로
     * 글의 존재가 새고, 성공 리다이렉트의 Location 으로 슬러그까지 나간다.
     *
     * 판정만 하고 예외는 던지지 않는다 — 404 를 어디서 내는지는 호출부에 남겨 둔다.
     */
    function post_viewable(Post $post): bool
    {
        // 비발행 글(초안·비공개)은 작성자 본인과 관리자에게만 열어 준다.
        if (! $post->isPublished() && ! is_owner_or_admin($post->user_id)) {
            return false;
        }

        // 숨김 카테고리(#67)의 글도 같은 규칙으로 가린다. 카테고리를 숨긴다는 건 그 글들을
        // 공개 화면에서 뺀다는 뜻이므로, 목록에서만 빼고 나머지를 열어 두면 슬러그나
        // 글 id 를 아는 사람에게 그대로 노출된다.
        if ($post->category_id !== null && ! is_owner_or_admin($post->user_id)) {
            $category = model(CategoryModel::class)->find($post->category_id);

            if ($category !== null && ! $category->is_visible) {
                return false;
            }
        }

        return true;
    }
}
