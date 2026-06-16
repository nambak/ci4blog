<?php

namespace App\Models;

use App\Entities\Post;
use CodeIgniter\Model;

class PostModel extends Model
{
    protected $table      = 'posts';
    protected $primaryKey = 'id';

    // 조회 결과를 배열이 아니라 Post 엔티티로 돌려받는다.
    protected $returnType = Post::class;

    // created_at / updated_at 을 모델이 자동으로 채운다.
    protected $useTimestamps = true;

    // 대량 할당을 허용할 필드. id 와 타임스탬프는 제외한다.
    protected $allowedFields = [
        'user_id',
        'title',
        'slug',
        'body',
    ];

    // 저장 전 검증 규칙. insert/update 시 자동으로 적용된다.
    protected $validationRules = [
        'title' => 'required|max_length[255]',
        'body'  => 'required',
    ];

    // 검증 실패 메시지(필요한 것만 한국어로 덮어쓴다).
    protected $validationMessages = [
        'title' => [
            'required'   => '제목을 입력해 주세요.',
            'max_length' => '제목은 255자를 넘을 수 없습니다.',
        ],
        'body' => [
            'required' => '본문을 입력해 주세요.',
        ],
    ];

    // 저장/수정 직전에 제목으로 slug 를 자동 생성한다.
    // (ep12~16 까지 컨트롤러가 임시 slug 를 채우던 것을 모델로 옮긴다.)
    protected $beforeInsert = ['generateSlug'];
    protected $beforeUpdate = ['generateSlug'];

    /**
     * 콜백: title 이 들어오면 slug 를 만들어 $data 에 채운다.
     * title 이 없는 부분 수정에서는 기존 slug 를 건드리지 않는다.
     */
    protected function generateSlug(array $data): array
    {
        if (! isset($data['data']['title'])) {
            return $data;
        }

        $base = $this->slugify((string) $data['data']['title']);

        // 수정 시 자기 자신은 중복 검사에서 제외한다.
        $excludeId = isset($data['id'][0]) ? (int) $data['id'][0] : null;

        $data['data']['slug'] = $this->uniqueSlug($base, $excludeId);

        return $data;
    }

    /**
     * 제목을 URL 안전한 slug 로 바꾼다.
     * 한국어 제목은 url_title()이 빈 문자열이 되므로, 글자/숫자(한글 포함)는
     * 살리고 공백은 하이픈으로 바꾼다. 결과가 비면 'post' 로 대체한다.
     */
    private function slugify(string $title): string
    {
        $slug = mb_strtolower(trim($title));
        $slug = preg_replace('/\s+/u', '-', $slug);             // 공백 → 하이픈
        // 허용: 소문자 영문·숫자·완성형 한글·하이픈 (Config\App::$permittedURIChars 와 동일 집합)
        $slug = preg_replace('/[^a-z0-9가-힣\-]+/u', '', $slug);
        $slug = preg_replace('/-+/', '-', $slug);               // 연속 하이픈 축약
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'post';
    }

    /**
     * slug 가 이미 있으면 -2, -3 … 을 붙여 유일하게 만든다.
     */
    private function uniqueSlug(string $base, ?int $excludeId = null): string
    {
        $slug   = $base;
        $suffix = 2;

        while (true) {
            $builder = $this->where('slug', $slug);
            if ($excludeId !== null) {
                $builder->where('id !=', $excludeId);
            }

            // countAllResults() 는 기본적으로 쿼리 빌더를 초기화하므로
            // 다음 루프의 조건이 누적되지 않는다.
            if ($builder->countAllResults() === 0) {
                return $slug;
            }

            $slug = $base . '-' . $suffix++;
        }
    }
}
