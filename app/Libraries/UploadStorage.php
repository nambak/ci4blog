<?php

namespace App\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * 업로드된 파일을 저장 디렉터리로 옮긴다.
 *
 * 저장 동작만 따로 떼어 둔 이유는 테스트다. UploadedFile::move() 는 내부에서
 * is_uploaded_file()·move_uploaded_file() 을 쓰는데, 이 둘은 실제 HTTP 업로드에서만
 * 참이라 CLI 로 도는 PHPUnit 에서는 통과할 수 없다. 이 한 겹이 있으면 테스트가
 * 저장 동작만 바꿔 끼우고 나머지 업로드 로직(검증·옛 파일 정리·DB 갱신)을 그대로
 * 태울 수 있다.
 */
class UploadStorage
{
    public function __construct(protected string $dir)
    {
    }

    /**
     * 업로드 파일을 충돌하지 않는 임의 이름으로 저장하고 그 파일명을 돌려준다.
     */
    public function store(UploadedFile $file): string
    {
        $name = $file->getRandomName();
        $file->move($this->dir, $name);

        return $name;
    }
}
