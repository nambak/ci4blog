<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * 블로그 표시 설정.
 *
 * 사이트 제목 등 "공개 저장소엔 일반값만 두고, 실제 운영값은 서버에서만"
 * 두고 싶은 값을 모은다. 아래 기본값은 공개되어도 무방한 일반값이며,
 * 운영 서버에서는 .env 로 덮어쓴다(.env 는 gitignore 됨):
 *
 *   blog.title = '실제 블로그 제목'
 */
class Blog extends BaseConfig
{
    /**
     * 헤더 브랜드·푸터·브라우저 탭에 쓰이는 사이트 제목.
     */
    public string $title = 'CI4 Blog';
}