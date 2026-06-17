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
     * 이 카테고리로 글 목록을 거르는 URL.
     *
     * 뷰에서 `$category->url` 로 접근한다.
     */
    public function getUrl(): string
    {
        return site_url('categories/' . $this->attributes['slug']);
    }
}
