<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * 커버리지 측정 대상 설정. (#115)
 *
 * 측정이 원리상 불가능한 것만 빼고, 나머지는 남긴다. 제외를 늘리면 수치가
 * 조용히 올라가므로 **과잉 제외를 막는 쪽이 이 테스트의 핵심**이다.
 *
 * @internal
 */
final class CoverageConfigTest extends CIUnitTestCase
{
    /** @var list<string> */
    private array $excluded = [];

    protected function setUp(): void
    {
        parent::setUp();

        $path = ROOTPATH . 'phpunit.dist.xml';
        $this->assertFileExists($path);

        $xml = simplexml_load_file($path);
        $this->assertNotFalse($xml, 'phpunit.dist.xml 을 파싱할 수 있어야 한다.');

        $exclude = $xml->source->exclude;
        $this->assertNotNull($exclude, '<source><exclude> 가 있어야 한다.');

        foreach ($exclude->directory as $directory) {
            $this->excluded[] = trim((string) $directory);
        }

        foreach ($exclude->file as $file) {
            $this->excluded[] = trim((string) $file);
        }
    }

    /**
     * 측정이 불가능한 것은 뺀다.
     *
     * Boot/*: 부트 훅이라 PHPUnit 부트스트랩 경로에서 실행되지 않는다.
     * Constants.php: 계측이 시작되기 전에 로드된다.
     * 둘 다 영원히 0% 로 남아 저커버리지 순위표를 오염시킨다.
     */
    public function testExcludesUnmeasurableFiles(): void
    {
        $this->assertContains('./app/Config/Boot', $this->excluded, 'Config/Boot 은 측정 대상에서 빠져야 한다.');
        $this->assertContains('./app/Config/Constants.php', $this->excluded, 'Constants.php 는 측정 대상에서 빠져야 한다.');
    }

    /** 기존 제외는 유지된다. */
    public function testKeepsExistingExclusions(): void
    {
        $this->assertContains('./app/Views', $this->excluded);
        $this->assertContains('./app/Config/Routes.php', $this->excluded);
    }

    /**
     * 과잉 제외 금지 — 수치를 부풀리지 않는다.
     *
     * 마이그레이션(80~100%)과 시더(98.6~100%)는 실제로 잘 덮여 있다. 빼면
     * down() 미커버라는 정보가 사라지고 비율만 좋아 보인다. 실제 앱 코드
     * (Controllers·Models·Commands 등)를 통째로 빼는 것도 마찬가지다.
     */
    public function testDoesNotExcludeMeasurableAppCode(): void
    {
        foreach ($this->excluded as $entry) {
            $this->assertNotSame('./app', $entry, 'app 전체를 제외하면 측정이 무의미하다.');
            $this->assertNotSame('./app/Database', $entry, '마이그레이션·시더는 실제로 커버되므로 남긴다.');
            $this->assertNotSame('./app/Database/Migrations', $entry, '마이그레이션은 남긴다.');
            $this->assertNotSame('./app/Database/Seeds', $entry, '시더는 남긴다.');
            $this->assertNotSame('./app/Commands', $entry, '커맨드는 남긴다(PostsImport 공백이 보여야 한다).');
            $this->assertNotSame('./app/Config', $entry, 'Config 전체가 아니라 측정 불가능한 항목만 뺀다.');
        }
    }
}
