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
        'is_visible',
    ];

    protected $validationRules = [
        // {id} 플레이스홀더(slug 규칙)를 채우려면 CI4가 'id' 자체의 규칙도 요구한다.
        'id'   => 'permit_empty',
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
     * 공개 화면의 메뉴·필터에서 쓰는 카테고리 목록(이름순).
     *
     * 숨김(is_visible = 0) 카테고리는 제외한다(#67). 관리 화면은 숨김 것도 봐야 하므로
     * 이 메서드가 아니라 withPostCounts() 를 쓴다.
     *
     * @return Category[]
     */
    public function menu(): array
    {
        return $this->where('is_visible', 1)->orderBy('name', 'ASC')->findAll();
    }

    /**
     * 관리 목록용: 카테고리별 글 수를 함께 반환한다(이름순).
     * select 에 괄호(COUNT)가 있으면 CI4가 식별자 프리픽스를 건너뛰므로
     * posts 테이블명만 prefixTable()로 직접 채워 넣는다(대시보드와 동일한 함정).
     *
     * @return Category[]  각 항목에 post_count(int) 속성이 붙는다.
     */
    public function withPostCounts(?string $search = null): array
    {
        $db = db_connect();

        $this->select('categories.*, COUNT(' . $db->prefixTable('posts') . '.id) AS post_count')
            ->join('posts', 'posts.category_id = categories.id', 'left')
            ->groupBy('categories.id')
            ->orderBy('categories.name', 'ASC');

        if ($search !== null && $search !== '') {
            $this->like('categories.name', $search);
        }

        return $this->findAll();
    }
}
