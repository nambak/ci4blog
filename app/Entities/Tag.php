<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class Tag extends Entity
{
    protected $dates = ['created_at', 'updated_at'];

    /** 태그 목록 URL. link_helper 의 tag_url() 을 쓴다(한글 slug 안전). */
    public function getUrl(): string
    {
        return tag_url($this->attributes['slug']);
    }
}
