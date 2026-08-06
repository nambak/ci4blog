<?php

namespace App\Models;

use App\Entities\Tag;
use App\Models\Concerns\GeneratesSlug;
use CodeIgniter\Model;

class TagModel extends Model
{
    use GeneratesSlug;

    /** 한 글에 붙일 수 있는 태그 수. 없으면 폼 하나로 태그를 수천 개 만들 수 있다. */
    public const MAX_TAGS = 10;

    /** 태그 이름 길이 상한(문자 수). name 컬럼이 VARCHAR(50) 이다. */
    public const MAX_NAME_LENGTH = 50;

    protected $table      = 'tags';
    protected $primaryKey = 'id';

    protected $returnType    = Tag::class;
    protected $useTimestamps = true;

    protected $allowedFields = ['name', 'slug'];

    /**
     * 쉼표로 구분된 입력을 정규화된 이름 배열로 바꾼다.
     *
     * 순서: 분리 → trim → 빈 것 제거 → 길이 상한 → 대소문자 무시 중복 제거 → 개수 상한.
     * 중복은 **먼저 나온 표기를 남긴다**(CI4, ci4 → CI4).
     *
     * @return list<string>
     */
    public function parseNames(string $input): array
    {
        $names = [];
        $seen  = [];

        foreach (explode(',', $input) as $raw) {
            $name = trim($raw);

            if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
                continue;
            }

            $key = mb_strtolower($name);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $names[]    = $name;

            if (count($names) >= self::MAX_TAGS) {
                break;
            }
        }

        return $names;
    }

    /**
     * 글의 태그를 주어진 이름들로 **전체 교체**한다.
     *
     * 차집합을 계산하지 않는 이유: 태그가 최대 10개라 지우고 다시 넣는 비용이
     * 무시할 만하고, 코드가 훨씬 단순하다.
     */
    public function syncForPost(int $postId, array $names): void
    {
        $db = db_connect();
        $db->table('post_tags')->where('post_id', $postId)->delete();

        foreach ($names as $name) {
            $tagId = $this->findOrCreateByName($name);

            // 바로 위에서 이 글의 연결을 모두 지웠으므로 중복이 생길 수 없다.
            // ignore(true) 는 유니크 키에 걸렸을 때 예외 대신 무시하게 하는 방어다.
            $db->table('post_tags')->ignore(true)->insert([
                'post_id' => $postId,
                'tag_id'  => $tagId,
            ]);
        }
    }

    /**
     * 그 글의 태그 목록(이름 오름차순).
     *
     * @return list<Tag>
     */
    public function forPost(int $postId): array
    {
        return $this
            ->select('tags.*')
            ->join('post_tags', 'post_tags.tag_id = tags.id')
            ->where('post_tags.post_id', $postId)
            ->orderBy('tags.name', 'ASC')
            ->findAll();
    }

    /** slug 로 태그 한 건. 없으면 null. */
    public function findBySlug(string $slug): ?Tag
    {
        return $this->where('slug', $slug)->first();
    }

    /**
     * 이름으로 태그를 찾고 없으면 만든다. 비교는 **대소문자 무시**다 —
     * 'CI4' 와 'ci4' 를 다른 태그로 두면 목록이 지저분해진다.
     */
    private function findOrCreateByName(string $name): int
    {
        $existing = $this
            ->where('LOWER(name)', mb_strtolower($name))
            ->first();

        if ($existing !== null) {
            return (int) $existing->id;
        }

        $this->insert([
            'name' => $name,
            'slug' => $this->uniqueSlug($this->slugify($name, 'tag')),
        ]);

        return (int) $this->getInsertID();
    }
}
