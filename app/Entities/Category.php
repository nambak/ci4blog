<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

/**
 * 카테고리 한 건을 나타내는 도메인 객체.
 *
 * 글 목록의 분류 메뉴와 `categories/{slug}` 필터에서 쓰인다.
 */
class Category extends Entity
{
    protected $dates = ['created_at', 'updated_at'];

    /**
     * MySQL TINYINT(1) 은 드라이버 설정에 따라 "1"/"0" 문자열로 올라온다.
     * truthy 판정만 하면 지금은 문제없지만 `=== true` 비교가 조용히 어긋나므로,
     * bool 컬럼을 들이는 김에 캐스팅을 명시한다.
     */
    protected $casts = ['is_visible' => 'boolean'];

    /**
     * 이 카테고리로 글 목록을 거르는 URL.
     *
     * 뷰에서 `$category->url` 로 접근한다.
     */
    public function getUrl(): string
    {
        return site_url('categories/' . $this->attributes['slug']);
    }
}
