<?php

namespace App\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * writable/uploads 의 이미지를 서빙한다(웹 루트 밖이라 컨트롤러로 서빙).
 *
 * 이 라우트 하나가 글 원본·목록 썸네일·아바타를 모두 서빙한다. 그래서 Posts
 * 컨트롤러가 아니라 여기에 둔다 — 예전에는 Posts::image() 가 아바타까지 서빙했다.
 */
class Uploads extends BaseController
{
    /**
     * 브라우저 캐시 유지 기간(초). 1년.
     *
     * 업로드마다 파일명이 새로 생기므로(UploadStorage 의 getRandomName) 같은 URL
     * 의 내용은 절대 변하지 않는다. 그래서 immutable 을 붙일 수 있다.
     */
    public const MAX_AGE = 31536000;

    /**
     * 서빙 허용 확장자와 그 Content-Type.
     *
     * allowlist 와 타입을 한 목록으로 합쳐 둔다 — 둘로 갈라지면 한쪽만 고쳐
     * 어긋나는 사고가 난다. mime_content_type() 을 쓰지 않는 이유는 두 가지다:
     * fileinfo 확장이 CI 워크플로 목록(.github/workflows/deploy.yml)에 없어 동작이
     * 확인된 바 없고, 업로드 시점에 이미 진짜 mime 을 검증하기 때문이다.
     */
    private const MIME_TYPES = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ];

    public function show(string $name): ResponseInterface
    {
        $name = basename($name); // 경로 탈출 방지(라우트 (:segment) 가 1차 방어)
        $type = self::MIME_TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null;
        $path = WRITEPATH . 'uploads/' . $name;

        // 목록 밖 확장자는 파일이 있어도 서빙하지 않는다. uploads 디렉터리의
        // 임의 파일(예: git 에 추적되는 index.html)을 밖으로 내보내지 않기 위해서다.
        if ($type === null || ! is_file($path)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $mtime = (int) filemtime($path);
        $size  = (int) filesize($path);
        $etag  = sprintf('"%x-%x"', $mtime, $size);

        // 반드시 setCache() 를 쓴다. setHeader('Cache-Control', ...) 는 생성자의
        // noCache() 가 남긴 "배열" 값에 덧붙어 no-store 가 앞에 남고(캐싱 무효),
        // 배열을 넘기면 appendHeader 의 ?string 타입 때문에 TypeError 로 죽는다.
        // setCache() 는 removeHeader('Cache-Control') 를 먼저 한다.
        //
        // last-modified 를 문자열로 넘기는 것도 의도다 — setLastModified() 는
        // DateTime 만 받고 DateTimeImmutable 은 instanceof 가 false 라 조용히 무시된다.
        $this->response->setCache([
            'etag'          => $etag,
            'last-modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
            'public',
            'max-age'       => self::MAX_AGE,
            'immutable',
        ]);

        return $this->response
            ->setHeader('Content-Type', $type)
            ->setBody((string) file_get_contents($path));
    }
}
