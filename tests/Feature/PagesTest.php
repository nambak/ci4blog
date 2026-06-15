<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * 정적 페이지에 대한 첫 Feature 테스트.
 *
 * FeatureTestTrait::call()로 실제 라우트를 호출해
 * 컨트롤러 → 뷰까지의 흐름이 정상 동작하는지 검증한다.
 *
 * ep11부터 공통 헤더가 auth()->loggedIn()을 호출하므로,
 * 정적 페이지 테스트도 Shield/Settings 테이블을 마이그레이션해 둬야 한다.
 */
final class PagesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    // null 이면 모든 네임스페이스(App·Shield·Settings)의 마이그레이션을 적용한다.
    protected $namespace = null;
    protected $refresh   = true;

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
