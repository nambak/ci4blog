<?php

namespace Tests\Support\Libraries;

use App\Libraries\UploadStorage;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * 테스트용 저장기.
 *
 * move_uploaded_file() 만 copy() 로 바꾼다. 파일명 생성은 진짜와 같은
 * getRandomName() 을 쓰고, 파일도 실제로 만든다. 이름만 돌려주는 가짜였다면
 * "새 파일이 저장된 뒤 옛 파일을 지운다"는 순서나 is_file() 분기가 통과하는
 * 척만 하게 된다.
 */
final class FakeUploadStorage extends UploadStorage
{
    /** @var list<string> 저장한 파일 경로(테스트가 정리한다) */
    public array $stored = [];

    public function store(UploadedFile $file): string
    {
        $name = $file->getRandomName();
        $path = $this->dir . '/' . $name;

        copy($file->getTempName(), $path);
        $this->stored[] = $path;

        return $name;
    }
}
