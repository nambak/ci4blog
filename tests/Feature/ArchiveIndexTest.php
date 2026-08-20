<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * /posts 목록. 예전엔 하단에 전체 글을 리스트 형식으로 나열하는 색인(#GSC)을
 * 같이 실었는데, 그리드에 이미 있는 글이 리스트에도 또 나와 사용자에게 혼선을
 * 줬다(#148). 그리드만 남기고 리스트 형식은 없앤다.
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

        // 발행 글 11건 — 2페이지가 생긴다(10건/페이지).
        $rows = [];

        for ($i = 1; $i <= 11; $i++) {
            $n      = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
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

    /** 리스트 형식 색인 자체가 더는 나오지 않는다. */
    public function testArchiveListIsNotRendered(): void
    {
        $body = $this->call('GET', 'posts')->response()->getBody();

        $this->assertStringNotContainsString('archive-index', $body);
    }

    /** 그리드에 있는 글이 리스트에 또 실리던 중복이 사라져, 한 번만 나온다. */
    public function testFirstPagePostsAppearOnlyOnce(): void
    {
        $body = $this->call('GET', 'posts')->response()->getBody();

        $this->assertSame(1, substr_count($body, 'ARCH-11'));
    }

    /** 2페이지로 밀린 글은 이제 1페이지 어디에도 나타나지 않는다 — 리스트가 없어졌으니까. */
    public function testPostsPushedToLaterPagesAreNotOnFirstPage(): void
    {
        $body = $this->call('GET', 'posts')->response()->getBody();

        $this->assertStringNotContainsString('ARCH-01', $body);
    }
}
