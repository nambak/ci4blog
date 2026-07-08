<?php

namespace App\Controllers;

use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;

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

    public function update(): RedirectResponse
    {
        $users = model(UserModel::class);
        $user  = $users->findById(auth()->id());

        // 사용자명 검증(본인 제외 유일성). {id}는 검증 data의 id로 채운다.
        $rules = [
            'id'       => 'permit_empty',
            'username' => 'required|max_length[30]|is_unique[users.username,id,{id}]',
        ];
        $data = [
            'id'       => $user->id,
            'username' => (string) $this->request->getPost('username'),
        ];

        if (! $this->validateData($data, $rules, [
            'username' => [
                'required'  => '사용자 이름을 입력해 주세요.',
                'is_unique' => '이미 사용 중인 사용자 이름입니다.',
            ],
        ])) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $user->username = $data['username'];
        $users->save($user);

        return redirect()->to('profile')->with('message', '프로필을 저장했습니다.');
    }
}
