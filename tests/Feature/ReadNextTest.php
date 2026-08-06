<?php

namespace Tests\Feature;

use App\Entities\Post;
use App\Models\CategoryModel;
use App\Models\PostModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * "이어 읽기"(이전 글 / 다음 글)에 대한 Feature 테스트. (#114)
 *
 * 순서 기준은 created_at 이다 — 목록·RSS·통계가 모두 이 컬럼을 발행일로 쓴다.
 * 다만 posts:import 는 front matter 에 published_at 이 없으면 date('Y-m-d H:i:s')
 * 를 넣으므로(#136) 같은 초의 글이 여럿 생긴다. 그래서 (created_at, id) 복합
 * 비교가 계약이고, testTiesAreBrokenByIdAndNeverReturnSelf 가 그것을 고정한다.
 */
final class ReadNextTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    /**
     * 글 하나를 만들고 created_at 을 원하는 시각으로 고정한다.
     *
     * created_at 을 insert 로 넣을 수 없다 — $allowedFields 에 없고 $useTimestamps
     * 가 now() 로 덮는다. 그래서 insert 뒤 쿼리 빌더로 직접 갱신한다
     * (Model::update() 로도 안 되는 이유가 같다).
     */
    private function makePost(string $title, string $createdAt, array $overrides = []): int
    {
        $posts = model(PostModel::class);
        $posts->insert(array_merge([
            'user_id'     => null,
            'category_id' => null,
            'title'       => $title,
            'body'        => '본문',
            'status'      => Post::STATUS_PUBLISHED,
        ], $overrides));

        $id = (int) $posts->getInsertID();
        db_connect()->table('posts')->where('id', $id)->update(['created_at' => $createdAt]);

        return $id;
    }

    private function findPost(int $id): Post
    {
        return model(PostModel::class)->find($id);
    }

    public function testNeighborsAreAdjacentByCreatedAt(): void
    {
        $old = $this->makePost('Older Post', '2026-01-01 00:00:00');
        $mid = $this->makePost('Middle Post', '2026-01-02 00:00:00');
        $new = $this->makePost('Newer Post', '2026-01-03 00:00:00');

        $posts    = model(PostModel::class);
        $previous = $posts->previousOf($this->findPost($mid));
        $next     = $posts->nextOf($this->findPost($mid));

        $this->assertNotNull($previous, '이전 글을 찾아야 한다');
        $this->assertNotNull($next, '다음 글을 찾아야 한다');
        $this->assertSame($old, (int) $previous->id);
        $this->assertSame($new, (int) $next->id);
    }

    public function testOldestHasNoPreviousAndNewestHasNoNext(): void
    {
        $old = $this->makePost('Oldest Post', '2026-01-01 00:00:00');
        $new = $this->makePost('Newest Post', '2026-01-03 00:00:00');

        $posts = model(PostModel::class);

        $this->assertNull($posts->previousOf($this->findPost($old)), '가장 오래된 글에는 이전이 없다');
        $this->assertNull($posts->nextOf($this->findPost($new)), '가장 최신 글에는 다음이 없다');
    }

    /**
     * created_at 이 같아도 id 로 갈려야 하고, 무엇보다 자기 자신이 나오면 안 된다.
     * 복합 비교가 빠지면 `created_at < :t` 가 동률을 통째로 제외해 이웃을 잃거나,
     * `<=` 로 느슨하게 두면 자기 자신이 걸린다.
     */
    public function testTiesAreBrokenByIdAndNeverReturnSelf(): void
    {
        $t = '2026-01-02 00:00:00';
        $a = $this->makePost('Tie A', $t);
        $b = $this->makePost('Tie B', $t);
        $c = $this->makePost('Tie C', $t);

        $posts    = model(PostModel::class);
        $previous = $posts->previousOf($this->findPost($b));
        $next     = $posts->nextOf($this->findPost($b));

        $this->assertNotNull($previous, '동률에서도 이전 글을 찾아야 한다');
        $this->assertNotNull($next, '동률에서도 다음 글을 찾아야 한다');
        $this->assertSame($a, (int) $previous->id);
        $this->assertSame($c, (int) $next->id);
        $this->assertNotSame($b, (int) $previous->id, '자기 자신이 이전 글로 나오면 안 된다');
        $this->assertNotSame($b, (int) $next->id, '자기 자신이 다음 글로 나오면 안 된다');
    }

    public function testDraftNeighborsAreSkipped(): void
    {
        $old = $this->makePost('Published Older', '2026-01-01 00:00:00');
        $this->makePost('Draft Between', '2026-01-02 00:00:00', ['status' => Post::STATUS_DRAFT]);
        $mid = $this->makePost('Current Post', '2026-01-03 00:00:00');

        $previous = model(PostModel::class)->previousOf($this->findPost($mid));

        $this->assertNotNull($previous);
        $this->assertSame($old, (int) $previous->id, '초안을 건너뛰고 그 너머 발행글이 나와야 한다');
    }

    /**
     * published() 스코프는 발행 상태만 보는 게 아니라 숨김 카테고리의 글까지 제외한다(#67).
     * 이웃 조회가 그 스코프를 실제로 태우는지 고정한다.
     */
    public function testHiddenCategoryNeighborsAreSkipped(): void
    {
        $categories = model(CategoryModel::class);
        $categories->insert(['name' => '공개분류']);
        $visible = (int) $categories->getInsertID();
        $categories->insert(['name' => '숨김분류', 'is_visible' => 0]);
        $hidden = (int) $categories->getInsertID();

        $old = $this->makePost('Visible Older', '2026-01-01 00:00:00', ['category_id' => $visible]);
        $this->makePost('Hidden Between', '2026-01-02 00:00:00', ['category_id' => $hidden]);
        $mid = $this->makePost('Current Post', '2026-01-03 00:00:00', ['category_id' => $visible]);

        $previous = model(PostModel::class)->previousOf($this->findPost($mid));

        $this->assertNotNull($previous);
        $this->assertSame($old, (int) $previous->id, '숨김 카테고리의 글을 건너뛰어야 한다');
    }
}
