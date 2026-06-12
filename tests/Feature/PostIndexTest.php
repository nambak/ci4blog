<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * 글 목록 화면에 대한 Feature 테스트.
 *
 * 마이그레이션으로 posts 테이블을 만들고 PostSeeder로 더미 글을 채운 뒤,
 * /posts 라우트가 모델에서 읽어 온 글들을 실제로 그려 주는지 검증한다.
 */
final class PostIndexTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    // App 네임스페이스의 마이그레이션을 매 테스트마다 새로 적용한다.
    protected $namespace = 'App';
    protected $refresh   = true;
    protected $seed      = 'App\Database\Seeds\PostSeeder';

    public function testIndexReturns200(): void
    {
        $result = $this->call('GET', 'posts');

        $result->assertStatus(200);
    }

    public function testIndexListsSeededPostTitles(): void
    {
        $result = $this->call('GET', 'posts');

        // 시더가 넣은 글 제목이 목록에 보여야 한다
        $result->assertSee('CodeIgniter 4로 블로그 만들기를 시작하며');
        $result->assertSee('시더로 현실적인 더미 데이터 채우기');
    }
}
