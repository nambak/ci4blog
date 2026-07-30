<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * robots.txt 가 sitemap 위치를 알린다. (#124)
 *
 * 크롤러가 sitemap 을 찾는 표준 경로다. 이 줄이 조용히 빠지면 sitemap 을
 * 만들어 둔 의미가 절반 사라지는데, 화면 어디에도 드러나지 않는다.
 *
 * @internal
 */
final class RobotsTxtTest extends CIUnitTestCase
{
    private string $robots;

    protected function setUp(): void
    {
        parent::setUp();

        $path = FCPATH . 'robots.txt';
        $this->assertFileExists($path);
        $this->robots = (string) file_get_contents($path);
    }

    /** 절대 URL 로 된 Sitemap 지시어가 있어야 한다(robots.txt 규격상 상대 경로 불가). */
    public function testAdvertisesSitemapWithAbsoluteUrl(): void
    {
        $this->assertMatchesRegularExpression(
            '#^Sitemap: https?://\S+/sitemap\.xml$#m',
            $this->robots,
            'robots.txt 에 절대 URL 형식의 Sitemap 지시어가 없다.'
        );
    }

    /**
     * 기존 전체 허용 정책은 그대로 둔다 — 이번 변경이 크롤링을 좁히면 안 된다.
     *
     * 'Disallow: /'(슬래시 포함)가 없는지 보는 것이 핵심이다. 현재의 'Disallow:'
     * (빈 값)는 전체 허용인데, 슬래시 하나가 붙으면 사이트 전체 차단으로 뒤집힌다.
     */
    public function testKeepsAllowAllPolicy(): void
    {
        $this->assertMatchesRegularExpression('/^User-agent: \*$/m', $this->robots);
        $this->assertStringNotContainsString('Disallow: /', $this->robots);
    }
}
