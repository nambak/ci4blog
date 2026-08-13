<?php

namespace Tests\Feature;

use App\Models\CategoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * 구조화 데이터(JSON-LD). (#GSC 색인)
 *
 * 사이트 전체에 JSON-LD 가 하나도 없었다. 구조화 데이터가 색인을 보장하지는
 * 않지만, 검색엔진이 페이지의 정체(글인가 목록인가)와 발행·수정 시각을 추측이
 * 아니라 선언으로 알게 된다. 특히 dateModified 는 "다시 읽어 볼 이유" 를 주는
 * 신호라, 크롤 후 색인이 거부된 글을 재평가시키려는 이번 목적과 맞닿아 있다.
 *
 * 이 테스트가 가장 신경 쓰는 것은 **깨지지 않는 JSON** 이다. 제목에 따옴표나
 * </script> 가 들어가면 문서가 통째로 망가지거나 스크립트가 조기 종료된다.
 * 그런 페이지는 구조화 데이터가 없느니만 못하다.
 */
final class StructuredDataTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('renderer');
        Services::resetSingle('pager');
    }

    // ---------------------------------------------------------------- 도우미

    /**
     * 페이지의 JSON-LD 블록을 전부 파싱해 돌려준다.
     *
     * 파싱에 실패하면 그 자리에서 실패시킨다 — 깨진 JSON 은 없는 것보다 나쁘다.
     *
     * @return list<array<string, mixed>>
     */
    private function jsonLd(TestResponse $result): array
    {
        preg_match_all(
            '#<script type="application/ld\+json">(.*?)</script>#s',
            $result->response()->getBody(),
            $matches
        );

        $blocks = [];

        foreach ($matches[1] as $raw) {
            $decoded = json_decode($raw, true);
            $this->assertNotNull($decoded, "JSON-LD 파싱 실패: {$raw}");
            $blocks[] = $decoded;
        }

        return $blocks;
    }

    /** @return array<string, mixed>|null */
    private function blockOfType(TestResponse $result, string $type): ?array
    {
        foreach ($this->jsonLd($result) as $block) {
            if (($block['@type'] ?? null) === $type) {
                return $block;
            }
        }

        return null;
    }

    private function makePost(array $overrides = []): string
    {
        $row = array_merge([
            'user_id'    => 1,
            'title'      => '구조화 데이터 테스트 글',
            'slug'       => 'structured-data-post',
            'body'       => '본문입니다.',
            'status'     => 'published',
            'created_at' => '2026-05-01 10:00:00',
            'updated_at' => '2026-06-02 11:00:00',
        ], $overrides);

        db_connect()->table('posts')->insert($row);

        return $row['slug'];
    }

    // ---------------------------------------------------------------- 글 상세

    public function testPostDetailHasBlogPosting(): void
    {
        $slug  = $this->makePost();
        $block = $this->blockOfType($this->call('GET', 'posts/' . $slug), 'BlogPosting');

        $this->assertNotNull($block, '글 상세에 BlogPosting 이 없다.');
        $this->assertSame('구조화 데이터 테스트 글', $block['headline']);
        $this->assertStringStartsWith('2026-05-01', $block['datePublished']);
        $this->assertStringStartsWith('2026-06-02', $block['dateModified']);
        $this->assertArrayHasKey('author', $block);
        $this->assertStringEndsWith('/posts/' . $slug, $block['mainEntityOfPage']);
    }

    /**
     * 발행일과 수정일이 서로 다른 값에서 온다.
     *
     * 둘 다 created_at 을 쓰면 dateModified 가 거짓이 된다 — 글을 고쳐도 "안 바뀐
     * 글" 이라고 선언하는 셈이라, 재평가를 유도하려는 목적과 정반대가 된다.
     */
    public function testPublishedAndModifiedDatesDiffer(): void
    {
        $slug  = $this->makePost();
        $block = $this->blockOfType($this->call('GET', 'posts/' . $slug), 'BlogPosting');

        $this->assertNotSame($block['datePublished'], $block['dateModified']);
    }

    /**
     * 제목에 따옴표나 </script> 가 있어도 JSON 이 깨지지 않는다.
     *
     * jsonLd() 가 파싱에 실패하면 여기서 죽는다. 그리고 스크립트가 조기 종료되지
     * 않았는지 원문에서 직접 확인한다.
     */
    public function testJsonLdSurvivesHostileTitle(): void
    {
        $slug = $this->makePost([
            'title' => '따옴표 " 와 </script> 가 든 제목',
            'slug'  => 'hostile-title',
        ]);

        $result = $this->call('GET', 'posts/' . $slug);
        $block  = $this->blockOfType($result, 'BlogPosting');

        $this->assertNotNull($block);
        $this->assertSame('따옴표 " 와 </script> 가 든 제목', $block['headline']);

        // 닫는 태그가 그대로 나가면 브라우저가 스크립트를 거기서 끊는다.
        $script = explode('<script type="application/ld+json">', $result->response()->getBody())[1] ?? '';
        $this->assertStringNotContainsString('</script> 가 든', $script, '스크립트가 조기 종료된다.');
    }

    // ---------------------------------------------------------------- 목록

    public function testPostListHasBreadcrumb(): void
    {
        $block = $this->blockOfType($this->call('GET', 'posts'), 'BreadcrumbList');

        $this->assertNotNull($block, '목록에 BreadcrumbList 가 없다.');
        $this->assertCount(2, $block['itemListElement'], '홈 > 글 목록 두 단계여야 한다.');
        $this->assertSame(1, $block['itemListElement'][0]['position']);
    }

    public function testCategoryBreadcrumbNamesTheCategory(): void
    {
        model(CategoryModel::class)->insert(['name' => '회고', 'slug' => 'retro']);

        $block = $this->blockOfType($this->call('GET', 'categories/retro'), 'BreadcrumbList');

        $this->assertNotNull($block);
        $this->assertCount(3, $block['itemListElement'], '홈 > 글 목록 > 카테고리 세 단계여야 한다.');
        $this->assertSame('회고', $block['itemListElement'][2]['name']);
    }

    /** 글 상세에는 목록용 빵부스러기를 싣지 않는다 — 그 화면의 정체는 글이다. */
    public function testPostDetailHasNoBreadcrumbList(): void
    {
        $slug = $this->makePost();

        $this->assertNull($this->blockOfType($this->call('GET', 'posts/' . $slug), 'BreadcrumbList'));
    }
}
