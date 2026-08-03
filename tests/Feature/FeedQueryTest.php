<?php

namespace Tests\Feature;

use App\Entities\Post;
use App\Models\CategoryModel;
use App\Models\PostModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 피드에 "무엇이" 실리는가 — 상한·정렬·제외 규칙. (#113)
 *
 * 응답 형식과 배선은 FeedTest 가 본다.
 *
 * @internal
 */
final class FeedQueryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    /** 제목만 다른 발행글을 순서대로 넣고 제목 목록을 돌려준다. */
    private function seedPublished(int $count, ?int $categoryId = null): array
    {
        $posts  = model(PostModel::class);
        $titles = [];

        for ($i = 1; $i <= $count; $i++) {
            $title = "글 {$i}";
            $posts->insert([
                'user_id'     => null,
                'category_id' => $categoryId,
                'title'       => $title,
                'body'        => '본문',
                'status'      => Post::STATUS_PUBLISHED,
            ]);
            $titles[] = $title;
        }

        return $titles;
    }

    private function titlesOf(array $posts): array
    {
        return array_map(static fn ($post) => $post->title, $posts);
    }

    /**
     * 상한을 넘으면 잘린다 — 개수와 "빠진 글"을 함께 본다.
     *
     * 개수만 세면 정렬이 뒤집혀 오래된 글 20개가 실려도 통과한다.
     */
    public function testLimitsToRequestedCount(): void
    {
        $this->seedPublished(21);

        $result = model(PostModel::class)->recentForFeed(20);
        $titles = $this->titlesOf($result);

        $this->assertCount(20, $result);
        $this->assertContains('글 21', $titles, '가장 최근 글이 빠졌다.');
        $this->assertNotContains('글 1', $titles, '가장 오래된 글이 잘리지 않았다.');
    }

    /** 기본 상한은 20이다. */
    public function testDefaultLimitIsTwenty(): void
    {
        $this->seedPublished(21);

        $this->assertCount(20, model(PostModel::class)->recentForFeed());
    }

    /** 최신순으로 나온다 — 첫 항목이 마지막에 넣은 글이다. */
    public function testOrdersByNewestFirst(): void
    {
        $this->seedPublished(3);

        $titles = $this->titlesOf(model(PostModel::class)->recentForFeed());

        $this->assertSame(['글 3', '글 2', '글 1'], $titles);
    }

    /** 초안은 실리지 않는다. */
    public function testExcludesDrafts(): void
    {
        $posts = model(PostModel::class);
        $posts->insert([
            'user_id'     => null,
            'category_id' => null,
            'title'       => '임시저장 글',
            'body'        => '본문',
            'status'      => Post::STATUS_DRAFT,
        ]);
        $this->seedPublished(1);

        $titles = $this->titlesOf($posts->recentForFeed());

        $this->assertContains('글 1', $titles);
        $this->assertNotContains('임시저장 글', $titles, '초안이 피드에 새어 나갔다.');
    }

    /** 숨김 카테고리의 글은 실리지 않는다(#67 의 스코프가 걸렸다는 증거). */
    public function testExcludesPostsInHiddenCategory(): void
    {
        $categories = model(CategoryModel::class);
        $categories->insert(['name' => '숨김분류', 'is_visible' => 0]);
        $hiddenId = $categories->getInsertID();

        $posts = model(PostModel::class);
        $posts->insert([
            'user_id'     => null,
            'category_id' => $hiddenId,
            'title'       => '숨김분류 글',
            'body'        => '본문',
            'status'      => Post::STATUS_PUBLISHED,
        ]);
        $this->seedPublished(1);

        $titles = $this->titlesOf($posts->recentForFeed());

        $this->assertContains('글 1', $titles);
        $this->assertNotContains('숨김분류 글', $titles, '숨김 카테고리 글이 피드에 새어 나갔다.');
    }

    /** 미분류(category_id IS NULL) 글은 실린다 — 숨길 카테고리 자체가 없다. */
    public function testIncludesUncategorizedPosts(): void
    {
        $this->seedPublished(1);

        $this->assertContains('글 1', $this->titlesOf(model(PostModel::class)->recentForFeed()));
    }

    /** 글이 하나도 없으면 빈 배열이다(예외를 던지지 않는다). */
    public function testReturnsEmptyArrayWhenNoPosts(): void
    {
        $this->assertSame([], model(PostModel::class)->recentForFeed());
    }
}
