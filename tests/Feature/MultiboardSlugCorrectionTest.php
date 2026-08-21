<?php

namespace Tests\Feature;

use App\Database\Migrations\CorrectMultiboardSlugs;
use App\Models\PostModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 운영 DB 의 멀티보드 ep01·ep02 slug 교정 마이그레이션.
 *
 * 두 글은 slug 보존 수정(PR #153) 이전에 발행돼서, 관리 화면을 거치며 slug 이
 * 제목 기준 한글로 덮어써졌다. 원고(front matter)가 정한 값으로 되돌린다.
 *
 * 이 마이그레이션은 운영 데이터 전용이라 로컬·테스트 DB 에는 대상이 없다.
 * 그래서 "대상이 없으면 아무 일도 하지 않는다" 가 정상 동작이고, 여기서 그것까지 못박는다.
 */
final class MultiboardSlugCorrectionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    private const OLD_EP01 = 'codeigniter-4로-멀티보드-만들기-1-왜-게시판마다-테이블을-만들지-않는가';
    private const NEW_EP01 = 'ci4-multiboard-01-three-designs';

    private function migrate(): void
    {
        (new CorrectMultiboardSlugs())->up();
    }

    private function makePost(string $slug, string $title = '제목'): int
    {
        $posts = model(PostModel::class);
        // 콜백이 slug 를 다시 만들지 않도록 API 와 같은 방식으로 넣는다.
        $posts->allowCallbacks(false)->insert(['title' => $title, 'body' => '본문', 'slug' => $slug]);

        return $posts->getInsertID();
    }

    public function testCorrectsOldKoreanSlug(): void
    {
        $id = $this->makePost(self::OLD_EP01);

        $this->migrate();

        $this->assertSame(self::NEW_EP01, model(PostModel::class)->find($id)->slug);
    }

    public function testDoesNothingWhenTargetIsAbsent(): void
    {
        // 로컬·테스트 DB 의 정상 상태다. 엉뚱한 글을 건드리면 안 된다.
        $id = $this->makePost('some-other-post');

        $this->migrate();

        $this->assertSame('some-other-post', model(PostModel::class)->find($id)->slug);
    }

    public function testDoesNotOverwriteWhenNewSlugAlreadyExists(): void
    {
        // 이미 올바른 slug 을 가진 글이 따로 있는데 옛 글까지 같은 값으로 바꾸면
        // slug 이 겹쳐 상세 조회가 어느 글을 집을지 알 수 없게 된다.
        $existing = $this->makePost(self::NEW_EP01, '이미 정상인 글');
        $stale    = $this->makePost(self::OLD_EP01, '옛 글');

        $this->migrate();

        $this->assertSame(self::NEW_EP01, model(PostModel::class)->find($existing)->slug);
        $this->assertSame(self::OLD_EP01, model(PostModel::class)->find($stale)->slug, '충돌하면 건드리지 않아야 한다');
    }

    public function testIsIdempotent(): void
    {
        $id = $this->makePost(self::OLD_EP01);

        $this->migrate();
        $this->migrate();

        $this->assertSame(self::NEW_EP01, model(PostModel::class)->find($id)->slug);
        // 두 번 돌려도 글이 늘거나 줄지 않는다.
        $this->assertSame(1, model(PostModel::class)->countAllResults());
    }
}
