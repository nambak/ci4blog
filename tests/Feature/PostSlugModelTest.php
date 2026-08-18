<?php

namespace Tests\Feature;

use App\Models\PostModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * PostModel 의 slug 생성 규칙(generateSlug 콜백) 테스트.
 *
 * 규칙은 세 갈래다.
 *  1. slug 를 명시하면 그 값을 쓴다(중복이면 -2 를 붙인다).
 *  2. slug 가 없는 신규 저장이면 제목에서 만든다.
 *  3. slug 가 없는 수정이면 손대지 않는다 — 발행된 글의 URL 은 제목을 고쳐도 그대로다.
 */
final class PostSlugModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    private function posts(): PostModel
    {
        return model(PostModel::class);
    }

    // ------------------------------------------------- 1. 명시한 slug 를 쓴다

    public function testExplicitSlugSurvivesInsert(): void
    {
        $posts = $this->posts();
        $posts->insert([
            'title' => '멀티보드 만들기 3 - 스키마 설계',
            'body'  => '본문',
            'slug'  => 'ci4-multiboard-03-schema-blueprint',
        ]);

        $this->assertSame(
            'ci4-multiboard-03-schema-blueprint',
            $posts->find($posts->getInsertID())->slug
        );
    }

    public function testExplicitSlugSurvivesUpdateAlongsideNewTitle(): void
    {
        $posts = $this->posts();
        $posts->insert(['title' => '옛 제목', 'body' => '본문', 'slug' => 'old-slug']);
        $id = $posts->getInsertID();

        // 제목과 slug 를 함께 보내면 제목이 아니라 보낸 slug 가 이긴다.
        $posts->update($id, [
            'title' => '새 제목',
            'body'  => '본문',
            'slug'  => 'brand-new-slug',
        ]);

        $this->assertSame('brand-new-slug', $posts->find($id)->slug);
    }

    public function testExplicitSlugGetsUniqueSuffixOnUpdate(): void
    {
        $posts = $this->posts();
        $posts->insert(['title' => '먼저 쓴 글', 'body' => '본문', 'slug' => 'taken-slug']);
        $posts->insert(['title' => '나중 쓴 글', 'body' => '본문', 'slug' => 'my-slug']);
        $id = $posts->getInsertID();

        // 남이 쓰고 있는 slug 로 바꾸려 하면 중복 처리가 걸린다.
        $posts->update($id, ['slug' => 'taken-slug']);

        $this->assertSame('taken-slug-2', $posts->find($id)->slug);
    }

    /**
     * 같은 slug 를 다시 보내도 접미사가 붙지 않는다.
     *
     * 중복 검사에서 자기 자신을 빼지 않으면 저장할 때마다 -2, -3 이 붙어
     * URL 이 계속 밀린다. 발행 API 는 매번 같은 slug 를 보내므로 바로 드러난다.
     */
    public function testResavingSameSlugDoesNotAddSuffix(): void
    {
        $posts = $this->posts();
        $posts->insert(['title' => '옛 제목', 'body' => '본문', 'slug' => 'stable-slug']);
        $id = $posts->getInsertID();

        $posts->update($id, ['title' => '고친 제목', 'body' => '본문', 'slug' => 'stable-slug']);

        $this->assertSame('stable-slug', $posts->find($id)->slug);
    }

    public function testExplicitSlugStillGetsUniqueSuffix(): void
    {
        $posts = $this->posts();
        $posts->insert(['title' => '먼저 쓴 글', 'body' => '본문', 'slug' => 'same-slug']);
        $posts->insert(['title' => '나중 쓴 글', 'body' => '본문', 'slug' => 'same-slug']);

        // 중복 처리는 명시한 slug 에도 그대로 걸린다.
        $this->assertSame('same-slug-2', $posts->find($posts->getInsertID())->slug);
    }

    // ------------------------------------- 2. slug 가 없는 신규 저장은 제목에서 만든다

    public function testSlugIsGeneratedFromTitleOnInsert(): void
    {
        $posts = $this->posts();
        $posts->insert(['title' => '제목에서 만든 슬러그', 'body' => '본문']);

        $this->assertSame('제목에서-만든-슬러그', $posts->find($posts->getInsertID())->slug);
    }

    public function testGeneratedSlugGetsUniqueSuffixOnInsert(): void
    {
        $posts = $this->posts();
        $posts->insert(['title' => '겹치는 제목', 'body' => '본문']);
        $posts->insert(['title' => '겹치는 제목', 'body' => '본문']);

        $this->assertSame('겹치는-제목-2', $posts->find($posts->getInsertID())->slug);
    }

    // ------------------------------------------ 3. slug 가 없는 수정은 손대지 않는다

    public function testUpdateWithoutSlugKeepsExistingSlug(): void
    {
        $posts = $this->posts();
        $posts->insert(['title' => '옛 제목', 'body' => '본문', 'slug' => 'keep-me']);
        $id = $posts->getInsertID();

        $posts->update($id, ['title' => '완전히 다른 제목', 'body' => '본문']);

        $post = $posts->find($id);
        // 제목은 반영되고
        $this->assertSame('완전히 다른 제목', $post->title);
        // slug 는 그대로다.
        $this->assertSame('keep-me', $post->slug);
    }

    /**
     * 제목 없는 부분 수정(관리 화면 일괄 상태 변경 등)도 slug 를 건드리지 않는다.
     */
    public function testPartialUpdateKeepsExistingSlug(): void
    {
        $posts = $this->posts();
        $posts->insert(['title' => '상태만 바꿀 글', 'body' => '본문', 'slug' => 'status-only']);
        $id = $posts->getInsertID();

        $posts->update($id, ['status' => 'draft']);

        $this->assertSame('status-only', $posts->find($id)->slug);
    }
}
