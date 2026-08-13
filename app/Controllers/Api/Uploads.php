<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 대표 이미지 업로드 API.
 *
 * 발행을 두 단계로 나눈 이유: 이미지는 multipart, 글은 JSON 이라 한 요청에
 * 섞으면 클라이언트도 서버도 분기가 늘어난다. 업로드가 파일명을 돌려주고
 * 발행이 그 이름을 참조하면 각 엔드포인트가 한 가지 일만 한다.
 *
 * 같은 이미지를 다시 올리면 파일이 하나 더 생긴다(getRandomName). 회차마다
 * 이미지가 한 장이라 실질적인 낭비가 아니고, 대신 같은 URL 의 내용이 절대
 * 변하지 않아 Uploads::show() 가 immutable 캐시를 붙일 수 있다.
 */
class Uploads extends BaseController
{
    use ResponseTrait;

    /** 목록 썸네일 크기. Posts 컨트롤러의 글 이미지 처리와 같은 값을 쓴다. */
    private const THUMB_WIDTH  = 400;
    private const THUMB_HEIGHT = 250;

    /**
     * POST /api/uploads
     *
     * multipart/form-data 로 `image` 필드에 파일을 보낸다.
     * 응답: {"name": "저장된파일명.png", "url": "https://.../uploads/저장된파일명.png"}
     */
    public function store(): ResponseInterface
    {
        $file = $this->request->getFile('image');

        // isValid() 로 보지 않는다. 그 안의 is_uploaded_file() 은 실제 HTTP 업로드에서만
        // 참이라, 이 한 줄이 CLI 로 도는 테스트에서 성공 경로를 통째로 막는다. 대신
        // 에러 코드로 가른다 — 웹 쪽 Posts::saveUploadedImage() 와 같은 방식이다.
        // 진짜 업로드가 아닌 파일은 UploadStorage 가 쓰는 move() 가 어차피 거부한다.
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return $this->failValidationErrors(['image' => 'image 필드로 파일을 보내 주세요.']);
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->failValidationErrors(['image' => $file->getErrorString()]);
        }

        // 관리자 화면의 글 이미지와 같은 규칙으로 검증한다. 두 경로가 갈라지면
        // 웹에서 거부된 파일이 API 로는 들어가는 구멍이 생긴다.
        if (! $this->validate([
            'image' => 'is_image[image]|mime_in[image,image/jpg,image/jpeg,image/png,image/webp]|max_size[image,2048]',
        ])) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $dir  = WRITEPATH . 'uploads';
        $name = service('uploadStorage')->store($file);

        // 목록용 썸네일. 없으면 목록 카드가 원본을 그대로 받아 느려진다.
        service('image')
            ->withFile($dir . '/' . $name)
            ->fit(self::THUMB_WIDTH, self::THUMB_HEIGHT, 'center')
            ->save($dir . '/thumb_' . $name);

        return $this->respondCreated([
            'name' => $name,
            'url'  => site_url('uploads/' . $name),
        ]);
    }
}
