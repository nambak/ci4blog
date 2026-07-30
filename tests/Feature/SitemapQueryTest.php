<?php

namespace Tests\Feature;

use App\Entities\Post;
use App\Models\CategoryModel;
use App\Models\PostModel;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * sitemap 이 쓰는 두 조회 메서드. (#124)
 *
 * 컨트롤러를 거치지 않고 모델만 본다 — 무엇이 sitemap 에 실리는가는
 * 이 두 쿼리가 결정하므로, 규칙을 여기서 못 박는다.
 *
 * @internal
 */
final class SitemapQueryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    /**
     * 공개 1 · 숨김 1 · 빈 카테고리 1 을 만들고 글을 상태별로 뿌린다.
     *
     * @return array{visible: int, hidden: int, empty: int}
     */
    private function seed(): array
    {
        $categories = model(CategoryModel::class);

        $categories->insert(['name' => '공개분류']);
        $visibleId = $categories->getInsertID();

        $categories->insert(['name' => '숨김분류', 'is_visible' => 0]);
        $hiddenId = $categories->getInsertID();

        $categories->insert(['name' => '빈분류']);
        $emptyId = $categories->getInsertID();

        $posts = model(PostModel::class);

        foreach ([
            ['공개 발행글', $visibleId, Post::STATUS_PUBLISHED],
            ['숨김분류 발행글', $hiddenId, Post::STATUS_PUBLISHED],
            ['임시저장 글', $visibleId, Post::STATUS_DRAFT],
            ['비공개 글', $visibleId, Post::STATUS_PRIVATE],
            ['미분류 발행글', null, Post::STATUS_PUBLISHED],
        ] as [$title, $categoryId, $status]) {
            $posts->insert([
                'user_id'     => null,
                'category_id' => $categoryId,
                'title'       => $title,
                'body'        => '본문',
                'status'      => $status,
            ]);
        }

        return ['visible' => $visibleId, 'hidden' => $hiddenId, 'empty' => $emptyId];
    }

    /** @return list<string> */
    private function sitemapPostTitles(): array
    {
        $slugs = array_map(
            static fn ($post) => $post->slug,
            model(PostModel::class)->publishedForSitemap()
        );

        // slug 만으로는 어떤 글인지 읽기 어려우므로 제목으로 되돌린다.
        return array_map(
            static fn ($slug) => model(PostModel::class)->where('slug', $slug)->first()->title,
            $slugs
        );
    }

    /** 발행글은 실린다. 미분류 발행글도 포함이다(카테고리 없음 ≠ 비공개). */
    public function testPublishedPostsAreIncluded(): void
    {
        $this->seed();

        $titles = $this->sitemapPostTitles();

        $this->assertContains('공개 발행글', $titles);
        $this->assertContains('미분류 발행글', $titles);
    }

    /** 임시저장·비공개 글은 실리지 않는다. */
    public function testDraftAndPrivatePostsAreExcluded(): void
    {
        $this->seed();

        $titles = $this->sitemapPostTitles();

        $this->assertNotContains('임시저장 글', $titles);
        $this->assertNotContains('비공개 글', $titles);
    }

    /** 숨김 카테고리의 글은 발행 상태여도 실리지 않는다. */
    public function testPostsInHiddenCategoryAreExcluded(): void
    {
        $this->seed();

        $this->assertNotContains('숨김분류 발행글', $this->sitemapPostTitles());
    }

    /**
     * 최신 글이 첫 원소로 온다.
     *
     * 정렬 취향이 아니라 계약이다 — 컨트롤러가 첫 원소의 updated_at 을
     * 홈·목록의 lastmod 로 쓰므로, 순서가 뒤집히면 조용히 낡은 시각이 나간다.
     */
    public function testResultIsOrderedByUpdatedAtDesc(): void
    {
        $this->seed();

        // 한 글만 확실히 최신으로 만든다(초 단위 동률을 피하려고 미래로 민다).
        $target = model(PostModel::class)->where('title', '미분류 발행글')->first();

        // 모델로는 updated_at 을 직접 못 쓴다 — $allowedFields 에 없어 무시되고
        // $useTimestamps 가 now() 로 덮는다. Model::builder() 는 protected 라
        // 테스트에서 못 부르므로 커넥션의 빌더를 쓴다(접두사는 table() 이 붙인다).
        db_connect()->table('posts')->where('id', $target->id)
            ->update(['updated_at' => Time::now()->addDays(1)->toDateTimeString()]);

        $first = model(PostModel::class)->publishedForSitemap()[0];

        $this->assertSame($target->slug, $first->slug, '가장 최근 수정된 글이 첫 원소여야 한다.');
    }

    /** updated_at 이 Time 으로 캐스팅돼 온다(컨트롤러가 format() 을 부른다). */
    public function testUpdatedAtIsCastToTime(): void
    {
        $this->seed();

        $this->assertInstanceOf(Time::class, model(PostModel::class)->publishedForSitemap()[0]->updated_at);
    }

    /** @return list<string> */
    private function sitemapCategorySlugs(): array
    {
        return array_column(model(CategoryModel::class)->visibleWithPublishedPosts(), 'slug');
    }

    /** 발행글이 있는 공개 카테고리는 실린다. */
    public function testVisibleCategoryWithPublishedPostIsIncluded(): void
    {
        $ids  = $this->seed();
        $slug = model(CategoryModel::class)->find($ids['visible'])->slug;

        $this->assertContains($slug, $this->sitemapCategorySlugs());
    }

    /**
     * 발행글이 하나도 없는 공개 카테고리는 빠진다.
     *
     * 빈 목록 페이지는 검색엔진이 thin content 로 보는 전형이라 색인 대상에서 뺀다.
     *
     * 이 규칙은 쿼리에서 INNER JOIN 과 WHERE p.status 두 겹으로 지켜지고, 둘 중
     * 하나만 남아도 결과가 같다(LEFT JOIN + 우측 테이블 WHERE 는 INNER 와 동치).
     * 그래서 한쪽만 없애는 뮤테이션으로는 이 테스트가 죽지 않는다 — 둘을 함께
     * 없애야 죽는 것을 실측으로 확인했다. 두 겹이 동시에 무너지는 것을 막는 단언이다.
     */
    public function testEmptyCategoryIsExcluded(): void
    {
        $ids  = $this->seed();
        $slug = model(CategoryModel::class)->find($ids['empty'])->slug;

        $this->assertNotContains($slug, $this->sitemapCategorySlugs());
    }

    /** 숨김 카테고리는 글이 있어도 빠진다. */
    public function testHiddenCategoryIsExcluded(): void
    {
        $ids  = $this->seed();
        $slug = model(CategoryModel::class)->find($ids['hidden'])->slug;

        $this->assertNotContains($slug, $this->sitemapCategorySlugs());
    }

    /**
     * 임시저장 글만 있는 카테고리도 빠진다.
     *
     * 위의 '빈분류' 만으로는 status 조건이 없어도 통과한다(글 자체가 없으므로).
     * 이 테스트가 status 필터를 특정한다.
     */
    public function testCategoryWithOnlyDraftPostsIsExcluded(): void
    {
        $categories = model(CategoryModel::class);
        $categories->insert(['name' => '초안만분류']);
        $draftOnlyId = $categories->getInsertID();

        model(PostModel::class)->insert([
            'user_id'     => null,
            'category_id' => $draftOnlyId,
            'title'       => '초안만분류 글',
            'body'        => '본문',
            'status'      => Post::STATUS_DRAFT,
        ]);

        $slug = $categories->find($draftOnlyId)->slug;

        $this->assertNotContains($slug, $this->sitemapCategorySlugs());
    }

    /** 카테고리의 last_updated 는 그 카테고리 발행글 중 가장 최근 값이다. */
    public function testCategoryLastUpdatedIsMaxOfItsPublishedPosts(): void
    {
        $categories = model(CategoryModel::class);
        $categories->insert(['name' => '두글분류']);
        $categoryId = $categories->getInsertID();

        $posts = model(PostModel::class);

        foreach (['오래된 글', '최근 글'] as $title) {
            $posts->insert([
                'user_id'     => null,
                'category_id' => $categoryId,
                'title'       => $title,
                'body'        => '본문',
                'status'      => Post::STATUS_PUBLISHED,
            ]);
        }

        $newer = $posts->where('title', '최근 글')->first();

        // 기대값을 먼저 고정한다 — 단언 시점에 Time::now() 를 다시 부르면
        // 그 사이 초가 넘어갔을 때 엉뚱한 이유로 실패한다.
        $expected = Time::now()->addDays(2)->toDateTimeString();

        db_connect()->table('posts')->where('id', $newer->id)->update(['updated_at' => $expected]);

        $slug = $categories->find($categoryId)->slug;
        $rows = model(CategoryModel::class)->visibleWithPublishedPosts();
        $row  = current(array_filter($rows, static fn ($r) => $r['slug'] === $slug));

        $this->assertNotFalse($row, '카테고리를 찾지 못했다.');
        $this->assertSame(
            $expected,
            Time::parse($row['last_updated'])->toDateTimeString(),
            'MAX(updated_at) 가 아니라 다른 행의 값이 왔다.'
        );
    }
}
