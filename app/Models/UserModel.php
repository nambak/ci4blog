<?php

namespace App\Models;

use CodeIgniter\Shield\Models\UserModel as ShieldUserModel;

/**
 * Shield 기본 UserModel 확장. 프로필 사진 경로(avatar)를 저장 허용 필드에 추가한다.
 * Config\Auth::$userProvider 가 이 모델을 가리키도록 바꿔서 auth()->getProvider()가
 * 이 인스턴스를 돌려주게 한다.
 */
class UserModel extends ShieldUserModel
{
    protected function initialize(): void
    {
        parent::initialize();

        $this->allowedFields[] = 'avatar';
    }
}
