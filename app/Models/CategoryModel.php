<?php

namespace App\Models;

use App\Entities\Category;
use CodeIgniter\Model;

class CategoryModel extends Model
{
    protected $table      = 'categories';
    protected $primaryKey = 'id';

    protected $returnType    = Category::class;
    protected $useTimestamps = true;

    protected $allowedFields = [
        'name',
        'slug',
    ];

    protected $validationRules = [
        'name' => 'required|max_length[100]',
        'slug' => 'required|max_length[100]|is_unique[categories.slug,id,{id}]',
    ];

    protected $validationMessages = [
        'name' => [
            'required' => '카테고리 이름을 입력해 주세요.',
        ],
        'slug' => [
            'required'  => '카테고리 슬러그를 입력해 주세요.',
            'is_unique' => '이미 사용 중인 슬러그입니다.',
        ],
    ];

    /**
     * 메뉴·필터에서 쓰는 전체 카테고리 목록(이름순).
     *
     * @return Category[]
     */
    public function menu(): array
    {
        return $this->orderBy('name', 'ASC')->findAll();
    }
}
