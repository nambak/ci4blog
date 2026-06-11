<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * 정적 페이지에 대한 첫 Feature 테스트.
 *
 * FeatureTestTrait::call()로 실제 라우트를 호출해
 * 컨트롤러 → 뷰까지의 흐름이 정상 동작하는지 검증한다.
 */
final class PagesTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testAboutPageReturns200(): void
    {
        $result = $this->call('GET', 'about');

        $result->assertStatus(200);
        $result->assertOK();
    }

    public function testAboutPageShowsHeading(): void
    {
        $result = $this->call('GET', 'about');

        // 공통 레이아웃 위에 본문 섹션이 끼워져 렌더링되는지 확인
        $result->assertSee('소개', 'h1');
    }
}
