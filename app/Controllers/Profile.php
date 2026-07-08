<?php

namespace App\Controllers;

use App\Models\UserModel;

/**
 * 로그인 사용자의 본인 프로필 수정.
 *
 * 라우트가 session 필터 안에 있어 이미 로그인 사용자만 도달한다.
 * 항상 auth()->id() 대상만 다룬다(URL에 id를 노출하지 않음).
 */
class Profile extends BaseController
{
    public function edit(): string
    {
        return view('profile/edit', [
            'user' => auth()->user(),
        ]);
    }
}
