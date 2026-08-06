<?php

namespace App\Models;

use App\Entities\Post;
use App\Models\Concerns\GeneratesSlug;
use CodeIgniter\I18n\Time;
use CodeIgniter\Model;

class PostModel extends Model
{
    use GeneratesSlug;

    protected $table      = 'posts';
    protected $primaryKey = 'id';

    // 조회 결과를 배열이 아니라 Post 엔티티로 돌려받는다.
    protected $returnType = Post::class;

    // created_at / updated_at 을 모델이 자동으로 채운다.
    protected $useTimestamps = true;

    // 대량 할당을 허용할 필드. id 와 타임스탬프는 제외한다.
    protected $allowedFields = [
        'user_id',
        'category_id',
        'title',
        'slug',
        'body',
        'image',
        'status',
    ];

    // 저장 전 검증 규칙. insert/update 시 자동으로 적용된다.
    protected $validationRules = [
        'title'       => 'required|max_length[255]',
        'body'        => 'required',
        // 카테고리는 선택 사항(permit_empty). 고른 경우엔 실존하는 카테고리 id 여야 한다.
        'category_id' => 'permit_empty|is_natural_no_zero|is_not_unique[categories.id]',
        // 상태는 폼/일괄 작업에서만 들어온다. 허용 값 밖이면 저장하지 않는다.
        'status'      => 'permit_empty|in_list[draft,published,private]',
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
        'category_id' => [
            'is_natural_no_zero' => '올바른 카테고리를 선택해 주세요.',
            'is_not_unique'      => '존재하지 않는 카테고리입니다.',
        ],
        'status' => [
            'in_list' => '올바른 상태가 아닙니다.',
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
     * 글을 지울 때 딸린 행도 함께 지운다.
     *
     * 마이그레이션에 FK 가 있지만 운영 DB 인 SQLite 는 이를 강제하지 않는다.
     * post_likes·comment_likes·comment_reports 는 아예 MySQL 에서만 FK 를 건다
     * (SQLite 는 FK 추가에 테이블 재생성이 필요해서). 그래서 앱에서 지운다.
     * FK 가 켜져 있어 이미 사라진 행을 한 번 더 지우는 것은 무해하다.
     *
     * 댓글 정리는 CommentModel::delete() 에 위임한다. 답글 재귀·신고·좋아요 정리
     * 규칙이 거기 한 곳에만 있게 하기 위해서다. FK CASCADE 로 댓글이 먼저
     * 사라지면 그 정리가 아예 호출되지 않으므로, 지우기 **전에** 댓글 id 를 모은다.
     *
     * 한계: $id 가 null 인 호출(where(...)->delete())은 정리를 타지 않는다.
     * 그 경우 경고 로그를 남겨 조용히 지나가지 않게 한다.
     * CommentModel 도 같은 한계를 갖는다. 현재 호출부는 모두 id 나 id 배열을 넘긴다.
     */
    public function delete($id = null, bool $purge = false)
    {
        $ids = array_values(array_filter(array_map('intval', (array) $id)));

        if ($ids === []) {
            // where(...)->delete() 처럼 id 없이 들어온 경우다. 어떤 행이 지워질지
            // 미리 알 수 없어 자식을 정리할 수 없다. 조용히 넘어가면 고아 행이
            // 소리 없이 쌓이므로 흔적을 남긴다.
            log_message('warning', 'PostModel::delete() 가 id 없이 호출돼 자식 행 정리를 건너뛴다.');

            return parent::delete($id, $purge);
        }

        $this->db->transStart();

        $commentIds = array_values(array_filter(array_map(
            'intval',
            array_column(
                $this->db->table('comments')->select('id')->whereIn('post_id', $ids)->get()->getResultArray(),
                'id'
            )
        )));

        if ($commentIds !== []) {
            model(CommentModel::class)->delete($commentIds);
        }

        $this->db->table('post_likes')->whereIn('post_id', $ids)->delete();

        $result = parent::delete($id, $purge);

        $this->db->transComplete();

        // 중간에 쿼리가 깨졌으면 롤백됐으므로 성공을 알리지 않는다.
        // (Posts::delete() 가 이 반환값을 보고 이미지 파일 정리 여부를 정한다.)
        if ($this->db->transStatus() === false) {
            return false;
        }

        return $result;
    }

    /**
     * 공개 화면 전용 스코프: 발행된 글만 남긴다.
     *
     * 공개 목록·홈·상세가 이 메서드를 명시적으로 체이닝한다. 모델 기본 스코프로
     * 숨기지 않는 이유는, 관리 화면이 매번 스코프를 풀어야 하는 쪽이 더 놀랍기 때문.
     */
    public function published(): static
    {
        // 숨김 카테고리(is_visible = 0)의 글도 함께 제외한다(#67).
        //
        // join 이 아니라 서브쿼리를 쓴다 — 이 모델에는 join 이 하나도 없어서, join 을
        // 들이면 이 스코프를 쓰는 모든 쿼리가 groupBy 를 필요로 하는 등 파급이 크다.
        //
        // category_id IS NULL(미분류)은 명시적으로 통과시킨다. 미분류는 카테고리
        // 레코드가 없는 가상 분류라 숨길 대상 자체가 없다 — 이 분기가 빠지면
        // 미분류 글이 통째로 사라진다.
        //
        // 괄호가 들어간 표현식에는 CI4 가 식별자 접두사를 붙이지 않으므로 테이블명을
        // prefixTable() 로 직접 채운다 — posts 쪽도 마찬가지다(테스트 DB 는 tests_ 접두사를
        // 쓰므로, 논리명을 그대로 두면 "no such column: posts.category_id" 로 깨진다).
        $db         = db_connect();
        $posts      = $db->prefixTable($this->table);
        $categories = $db->prefixTable('categories');

        // 첫 where 는 괄호가 없어 CI4 가 접두사를 붙여 주므로 논리명을 쓴다.
        return $this->where($this->table . '.status', Post::STATUS_PUBLISHED)
            ->where(
                '(' . $posts . '.category_id IS NULL'
                . ' OR ' . $posts . '.category_id IN'
                . ' (SELECT id FROM ' . $categories . ' WHERE is_visible = 1))',
                null,
                false
            );
    }

    /**
     * 발행일 순서로 바로 앞(더 오래된) 글. 없으면 null. (#114)
     */
    public function previousOf(Post $post): ?Post
    {
        return $this->neighborOf($post, true);
    }

    /**
     * 발행일 순서로 바로 뒤(더 최신) 글. 없으면 null. (#114)
     */
    public function nextOf(Post $post): ?Post
    {
        return $this->neighborOf($post, false);
    }

    /**
     * 이웃 글 한 건을 찾는다.
     *
     * created_at 만으로 비교하면 같은 시각의 글에서 무너진다 — posts:import 는
     * front matter 에 published_at 이 없으면 date('Y-m-d H:i:s') 를 넣으므로(#136)
     * 한 번의 import 로 같은 초의 글이 여럿 생긴다. `<` 로 자르면 그 글들을 통째로
     * 건너뛰고, `<=` 로 느슨히 두면 자기 자신이 걸린다. 그래서 (created_at, id)
     * 복합 비교를 쓴다.
     *
     * 범위는 published() 에 맡긴다. 이 스코프는 발행 상태뿐 아니라 숨김
     * 카테고리(is_visible = 0)의 글까지 제외하므로(#67), 조건을 직접 다시 쓰면
     * 그 규칙이 이 경로에서만 새어 나간다.
     *
     * @param bool $older true 면 더 오래된 쪽, false 면 더 최신 쪽
     */
    private function neighborOf(Post $post, bool $older): ?Post
    {
        $createdAt = $post->created_at instanceof Time
            ? $post->created_at->toDateTimeString()
            : (string) $post->created_at;
        $id = (int) $post->id;

        $op    = $older ? '<' : '>';
        $order = $older ? 'DESC' : 'ASC';

        // published() 가 쌓는 조건은 first() 가 쿼리를 실행하는 시점에 리셋되므로,
        // 같은 인스턴스로 previousOf 와 nextOf 를 잇달아 불러도 조건이 겹치지 않는다
        // (testNeighborsAreAdjacentByCreatedAt 이 그 호출 패턴을 그대로 쓴다).
        return $this->published()
            ->groupStart()
                ->where($this->table . '.created_at ' . $op, $createdAt)
                ->orGroupStart()
                    ->where($this->table . '.created_at', $createdAt)
                    ->where($this->table . '.id ' . $op, $id)
                ->groupEnd()
            ->groupEnd()
            ->orderBy($this->table . '.created_at', $order)
            ->orderBy($this->table . '.id', $order)
            ->first();
    }

    /**
     * sitemap 용 발행글 목록. slug 와 updated_at 만 있으면 된다. (#124)
     *
     * published() 스코프를 그대로 쓴다 — 발행 상태와 숨김 카테고리 제외가 이미
     * 그 안에 있어, 공개 화면과 sitemap 의 판정이 갈라질 수 없다.
     *
     * updated_at DESC 정렬은 취향이 아니라 계약이다: 첫 행의 updated_at 이 곧
     * 사이트 전체의 최신 시각이라, 홈·목록의 lastmod 를 얻는 집계 쿼리가 없어진다.
     *
     * @return list<Post>
     */
    public function publishedForSitemap(): array
    {
        return $this->published()
            ->select($this->table . '.slug, ' . $this->table . '.updated_at')
            ->orderBy($this->table . '.updated_at', 'DESC')
            ->findAll();
    }

    /**
     * 피드에 실을 최근 발행글. (#113)
     *
     * sitemap 과 정렬 기준이 다르다 — sitemap 의 updated_at 은 크롤러에게 "최근
     * 바뀐 것" 을 알리는 값이지만, 피드는 "새로 나온 순" 이 구독 계약이다.
     * created_at 을 쓰면 오래된 글을 고쳐도 구독자 리더에서 순서가 흔들리지 않는다.
     *
     * id 2차 정렬은 같은 초에 들어간 글들의 순서를 고정하기 위한 것이다 —
     * 2차 키가 없으면 순서가 DB 구현에 맡겨진다.
     *
     * @return list<Post>
     */
    public function recentForFeed(int $limit = 20): array
    {
        return $this->published()
            ->orderBy($this->table . '.created_at', 'DESC')
            ->orderBy($this->table . '.id', 'DESC')
            ->findAll($limit);
    }

    /**
     * 상태별 글 수. 관리 화면의 탭 카운트와 통계 카드가 쓴다.
     *
     * $search 가 주어지면 제목 like 로 좁힌 결과의 분포를 돌려준다(탭 숫자와
     * 실제로 보이는 행 수가 어긋나지 않도록).
     *
     * @return array{draft:int, published:int, private:int}
     */
    public function statusCounts(?string $search = null): array
    {
        // 모델의 공유 빌더 상태를 오염시키지 않도록 별도 빌더를 쓴다.
        $builder = $this->db->table($this->table)
            ->select('status, COUNT(*) AS cnt')
            ->groupBy('status');

        if ($search !== null && $search !== '') {
            $builder->like('title', $search);
        }

        // 0건인 상태는 GROUP BY 결과에 아예 없다. 세 상태를 0으로 깔아 두고 덮어쓴다.
        $counts = [
            Post::STATUS_DRAFT     => 0,
            Post::STATUS_PUBLISHED => 0,
            Post::STATUS_PRIVATE   => 0,
        ];

        foreach ($builder->get()->getResultArray() as $row) {
            $counts[$row['status']] = (int) $row['cnt'];
        }

        return $counts;
    }
}
