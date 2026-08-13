<?php

namespace Tests\Feature;

use App\Models\CategoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * /posts 하단의 전체 글 색인. (#GSC 색인)
 *
 * 왜 만드는가 — 라이브에서 홈부터 링크를 따라가 각 글까지의 최소 클릭 수를 재 봤다.
 * 31건 중 20건이 3클릭, 1건이 4클릭이었고, 페이지네이션 링크를 빼면 **12건만**
 * 도달 가능했다. 나머지 19건은 ?page=N 을 거쳐야만 닿는다. GSC 에서 "크롤조차 안 된"
 * 10건은 그중에서도 9건이 페이지네이션 없이는 아예 닿지 않는 글이었다.
 *
 * 이전/다음 글 사슬(#114)은 이미 있지만 깊이를 줄이지 못한다 — 31개를 사슬로 이으면
 * 끝에서 끝까지 30클릭이다. 크롤 예산은 깊이에 민감하다.
 *
 * 그래서 목록 1페이지에 전체 글 링크를 둔다. /posts 가 홈에서 1클릭이므로 모든 글이
 * 2클릭 안에 들어온다.
 *
 * 색인을 보장하지는 않는다. 크롤러가 찾아올 경로를 만드는 것까지가 우리 몫이고,
 * 색인 여부는 Google 이 정한다.
 */
final class ArchiveIndexTest extends CIUnitTestCase
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

        // 발행 글 11건 — 2페이지가 생긴다(10건/페이지). ARCH-01 이 가장 오래돼
        // 2페이지로 밀리는 글이고, 그것이 1페이지에서 보이는지가 이 테스트의 핵심이다.
        $rows = [];

        for ($i = 1; $i <= 11; $i++) {
            $n       = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $rows[] = [
                'user_id'    => 1,
                'title'      => 'ARCH-' . $n,
                'slug'       => 'arch-' . $n,
                'body'       => '본문',
                'status'     => 'published',
                'created_at' => '2026-05-01 00:' . $n . ':00',
                'updated_at' => '2026-05-01 00:' . $n . ':00',
            ];
        }

        db_connect()->table('posts')->insertBatch($rows);
    }

    /** 목록 1페이지에서 2페이지로 밀린 글까지 닿는다. */
    public function testFirstPageLinksToPostsOnLaterPages(): void
    {
        $result = $this->call('GET', 'posts');

        // 카드 목록에는 없는 글이어야 한다 — 그래야 색인이 일하고 있다는 뜻이다.
        $result->assertDontSee('ARCH-01</a></h2>');
        $result->assertSee('ARCH-01');
    }

    /** 전체 글이 빠짐없이 들어간다. */
    public function testEveryPublishedPostIsLinked(): void
    {
        $body = $this->call('GET', 'posts')->response()->getBody();

        for ($i = 1; $i <= 11; $i++) {
            $slug = 'arch-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $this->assertStringContainsString($slug, $body, "{$slug} 링크가 없다.");
        }
    }

    /**
     * 초안은 새지 않는다.
     *
     * 색인용 링크 목록은 공개 화면이다. 여기서 published() 스코프를 빠뜨리면
     * 남의 눈에 띄지 않던 초안 제목이 목록 맨 아래에 통째로 실린다.
     */
    public function testDraftsAreNotLinked(): void
    {
        db_connect()->table('posts')->insert([
            'user_id'    => 1,
            'title'      => '아직-쓰는-중',
            'slug'       => 'still-writing',
            'body'       => '본문',
            'status'     => 'draft',
            'created_at' => '2026-05-01 00:30:00',
            'updated_at' => '2026-05-01 00:30:00',
        ]);

        $body = $this->call('GET', 'posts')->response()->getBody();

        $this->assertStringNotContainsString('still-writing', $body);
        $this->assertStringNotContainsString('아직-쓰는-중', html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** 숨김 카테고리의 글도 새지 않는다(#67 과 같은 규칙). */
    public function testPostsInHiddenCategoryAreNotLinked(): void
    {
        $categories = model(CategoryModel::class);
        $categories->insert(['name' => '숨긴분류', 'slug' => 'hidden']);
        $id = $categories->getInsertID();
        db_connect()->table('categories')->where('id', $id)->update(['is_visible' => 0]);

        db_connect()->table('posts')->insert([
            'user_id'     => 1,
            'title'       => '숨긴-카테고리-글',
            'slug'        => 'hidden-cat-post',
            'body'        => '본문',
            'status'      => 'published',
            'category_id' => $id,
            'created_at'  => '2026-05-01 00:40:00',
            'updated_at'  => '2026-05-01 00:40:00',
        ]);

        $body = $this->call('GET', 'posts')->response()->getBody();

        $this->assertStringNotContainsString('hidden-cat-post', $body);
    }

    /**
     * 2페이지 이후에는 색인을 싣지 않는다.
     *
     * 어차피 1페이지에 있는 것과 같은 링크 묶음이다. 페이지마다 반복하면 같은
     * 내용이 페이지 수만큼 늘어나 중복이 된다 — 방금 canonical 로 정리한 것을
     * 도로 어지럽히는 셈이다.
     */
    public function testLaterPagesDoNotRepeatTheIndex(): void
    {
        $body = $this->call('GET', 'posts', ['page' => '2'])->response()->getBody();

        $this->assertStringNotContainsString('archive-index', $body);
    }

    /** 카테고리·태그·검색으로 좁힌 화면에도 싣지 않는다. 그 맥락과 맞지 않는다. */
    public function testFilteredListsDoNotShowTheIndex(): void
    {
        $body = $this->call('GET', 'posts', ['q' => 'ARCH'])->response()->getBody();

        $this->assertStringNotContainsString('archive-index', $body);
    }
}
