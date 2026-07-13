<?php

namespace Tests\Feature;

use App\Entities\Post;
use App\Models\PostModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * posts.status 컬럼과 PostModel 의 상태 관련 스코프/집계 테스트.
 */
final class PostStatusModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    public function testStatusDefaultsToPublished(): void
    {
        $posts = model(PostModel::class);
        $posts->insert(['title' => '기본값 글', 'body' => '본문']);

        $post = $posts->find($posts->getInsertID());

        $this->assertSame(Post::STATUS_PUBLISHED, $post->status);
    }

    public function testRejectsUnknownStatus(): void
    {
        $posts = model(PostModel::class);

        $this->assertFalse($posts->insert(['title' => '이상한 글', 'body' => '본문', 'status' => 'archived']));
        $this->assertArrayHasKey('status', $posts->errors());
    }

    public function testPublishedScopeExcludesDraftAndPrivate(): void
    {
        $posts = model(PostModel::class);
        $posts->insert(['title' => '공개된 글', 'body' => '본문', 'status' => Post::STATUS_PUBLISHED]);
        $posts->insert(['title' => '초안 상태 글', 'body' => '본문', 'status' => Post::STATUS_DRAFT]);
        $posts->insert(['title' => '숨긴 글', 'body' => '본문', 'status' => Post::STATUS_PRIVATE]);

        $titles = array_map(static fn ($post) => $post->title, $posts->published()->findAll());

        $this->assertSame(['공개된 글'], $titles);
    }

    public function testStatusCountsReturnsAllThreeKeysEvenWhenZero(): void
    {
        $posts = model(PostModel::class);
        $posts->insert(['title' => '공개된 글', 'body' => '본문', 'status' => Post::STATUS_PUBLISHED]);
        $posts->insert(['title' => '초안 상태 글', 'body' => '본문', 'status' => Post::STATUS_DRAFT]);

        // 0건인 private 도 키가 있어야 뷰에서 ?? 없이 바로 쓸 수 있다.
        $this->assertSame(['draft' => 1, 'published' => 1, 'private' => 0], $posts->statusCounts());
    }

    public function testStatusCountsRespectsTitleSearch(): void
    {
        $posts = model(PostModel::class);
        $posts->insert(['title' => 'CI4 공개된 글', 'body' => '본문', 'status' => Post::STATUS_PUBLISHED]);
        $posts->insert(['title' => 'CI4 초안 상태 글', 'body' => '본문', 'status' => Post::STATUS_DRAFT]);
        $posts->insert(['title' => '무관한 초안', 'body' => '본문', 'status' => Post::STATUS_DRAFT]);

        $this->assertSame(['draft' => 1, 'published' => 1, 'private' => 0], $posts->statusCounts('CI4'));
    }

    public function testStatusLabelIsKorean(): void
    {
        $this->assertSame('임시저장', (new Post(['status' => Post::STATUS_DRAFT]))->statusLabel());
        $this->assertSame('발행됨', (new Post(['status' => Post::STATUS_PUBLISHED]))->statusLabel());
        $this->assertSame('비공개', (new Post(['status' => Post::STATUS_PRIVATE]))->statusLabel());
    }

    public function testIsPublished(): void
    {
        $this->assertTrue((new Post(['status' => Post::STATUS_PUBLISHED]))->isPublished());
        $this->assertFalse((new Post(['status' => Post::STATUS_DRAFT]))->isPublished());
    }
}
