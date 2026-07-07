<?php

namespace App\Models;

use App\Entities\Category;
use App\Models\Concerns\GeneratesSlug;
use CodeIgniter\Model;

class CategoryModel extends Model
{
    use GeneratesSlug;

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
        'slug' => 'permit_empty|max_length[100]|is_unique[categories.slug,id,{id}]',
    ];

    protected $validationMessages = [
        'name' => [
            'required' => '카테고리 이름을 입력해 주세요.',
        ],
        'slug' => [
            'is_unique' => '이미 사용 중인 슬러그입니다.',
        ],
    ];

    protected $beforeInsert = ['generateSlug'];
    protected $beforeUpdate = ['generateSlug'];

    /**
     * 콜백: slug 가 비었으면 name 으로 자동 생성한다.
     * 사용자가 slug 를 직접 채웠으면 그대로 둔다(유일성은 검증 규칙이 확인).
     */
    protected function generateSlug(array $data): array
    {
        if (! empty($data['data']['slug'])) {
            return $data;
        }
        if (! isset($data['data']['name'])) {
            return $data;
        }

        $base      = $this->slugify((string) $data['data']['name'], 'category');
        $excludeId = isset($data['id'][0]) ? (int) $data['id'][0] : null;

        $data['data']['slug'] = $this->uniqueSlug($base, $excludeId);

        return $data;
    }

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
