<?php

namespace Tests\Feature;

use App\Entities\Post;
use App\Models\CategoryModel;
use App\Models\PostModel;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * sitemap 이 쓰는 글 조회. (#124)
 *
 * 컨트롤러를 거치지 않고 모델만 본다 — 무엇이 sitemap 에 실리는가는
 * 이 쿼리가 결정하므로, 규칙을 여기서 못 박는다.
 *
 * 카테고리 조회(visibleWithPublishedPosts)도 여기서 함께 다뤘으나, 카테고리
 * URL 을 sitemap 에서 빼면서(#GSC 중복 목록) 메서드와 함께 지웠다.
 *
 * @internal
 */
final class SitemapQueryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    /** 공개 1 · 숨김 1 카테고리를 만들고 글을 상태별로 뿌린다. */
    private function seed(): void
    {
        $categories = model(CategoryModel::class);

        $categories->insert(['name' => '공개분류']);
        $visibleId = $categories->getInsertID();

        $categories->insert(['name' => '숨김분류', 'is_visible' => 0]);
        $hiddenId = $categories->getInsertID();

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
}
