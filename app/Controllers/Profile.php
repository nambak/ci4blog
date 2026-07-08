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

        // 비밀번호 변경(선택). new_password 가 비어 있으면 건드리지 않는다.
        $newPassword = (string) $this->request->getPost('new_password');
        if ($newPassword !== '') {
            // 1) 현재 비밀번호 확인(로그인시키지 않고 검증만).
            $check = auth('session')->check([
                'email'    => $user->email,
                'password' => (string) $this->request->getPost('current_password'),
            ]);
            if (! $check->isOK()) {
                return redirect()->back()
                    ->with('errors', ['현재 비밀번호가 올바르지 않습니다.']);
            }

            // 2) 새 비밀번호 확인란 일치 + 최소 길이.
            $confirm = (string) $this->request->getPost('new_password_confirm');
            if ($newPassword !== $confirm) {
                return redirect()->back()
                    ->with('errors', ['새 비밀번호가 서로 일치하지 않습니다.']);
            }
            if (mb_strlen($newPassword) < 8) {
                return redirect()->back()
                    ->with('errors', ['새 비밀번호는 8자 이상이어야 합니다.']);
            }

            $user->setPassword($newPassword);
        }

        // 아바타 업로드(선택). 파일이 없으면 기존 값을 유지한다.
        $newAvatar = $this->saveUploadedAvatar();
        if ($newAvatar === false) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }
        if ($newAvatar !== null) {
            $oldAvatar      = $user->avatar;
            $user->avatar   = $newAvatar;
            // 새 파일 저장이 확정됐으니 기존 파일 정리(있으면).
            $this->deleteAvatarFile($oldAvatar);
        }

        $user->username = $data['username'];
        $users->save($user);

        return redirect()->to('profile')->with('message', '프로필을 저장했습니다.');
    }

    public function deleteAvatar(): RedirectResponse
    {
        $users = model(UserModel::class);
        $user  = $users->findById(auth()->id());

        $this->deleteAvatarFile($user->avatar);
        $user->avatar = null;
        $users->save($user);

        return redirect()->to('profile')->with('message', '프로필 사진을 삭제했습니다.');
    }

    /**
     * 업로드된 아바타를 검증·저장하고 저장 파일명을 돌려준다.
     * 글 이미지와 같은 writable/uploads 에 저장한다(서빙: uploads/(:segment)).
     *
     * @return string|false|null 저장 파일명 / 검증 실패(false) / 업로드 없음(null)
     */
    private function saveUploadedAvatar(): string|false|null
    {
        $file = $this->request->getFile('avatar');

        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (! $this->validate([
            'avatar' => 'is_image[avatar]|mime_in[avatar,image/jpg,image/jpeg,image/png,image/gif]|max_size[avatar,2048]',
        ])) {
            return false;
        }

        $dir  = WRITEPATH . 'uploads';
        $name = $file->getRandomName();
        $file->move($dir, $name);

        return $name;
    }

    /**
     * 아바타 파일을 파일시스템에서 지운다(있을 때만).
     */
    private function deleteAvatarFile(?string $name): void
    {
        if ($name === null || $name === '') {
            return;
        }

        $path = WRITEPATH . 'uploads/' . basename($name);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
