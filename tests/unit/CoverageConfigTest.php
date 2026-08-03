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
        // SimpleXML 은 없는 자식에 null 이 아니라 빈 객체를 준다 — assertNotNull 은
        // 절대 실패하지 않는다. 실제로 항목이 있는지는 개수로 물어야 한다.
        $this->assertGreaterThan(
            0,
            count($exclude->directory) + count($exclude->file),
            '<source><exclude> 에 항목이 있어야 한다.'
        );

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
     * 과잉 제외 금지 — 제외 목록을 정확한 집합으로 못 박는다.
     *
     * 이전에는 알려진 몇몇 경로(./app, ./app/Database, ./app/Commands 등)만
     * assertNotSame 으로 나열하는 블랙리스트였다. 그러면 목록에 없는 새 경로
     * (예: ./app/Models, ./app/Controllers)가 제외에 추가돼도 테스트는 그대로
     * 통과하고 커버리지 수치만 조용히 올라간다 — 막으려던 바로 그 일이 열거에
     * 없는 경로로 그대로 일어난다.
     *
     * 화이트리스트(정확 집합)로 뒤집으면 제외 목록에 무엇이 늘거나 줄어도
     * 반드시 이 테스트가 걸린다. 마이그레이션(80~100%)과 시더(98.6~100%)는
     * 실제로 잘 덮여 있으므로 목록에 없다 — 빼면 down() 미커버라는 정보가
     * 사라지고 비율만 좋아 보인다.
     */
    public function testExcludesNothingBeyondTheUnmeasurable(): void
    {
        $expected = [
            './app/Views',
            './app/Config/Boot',
            './app/Config/Constants.php',
            './app/Config/Routes.php',
        ];

        sort($expected);
        $actual = $this->excluded;
        sort($actual);

        $this->assertSame($expected, $actual, '제외 목록에 새 항목이 늘면 커버리지 수치가 조용히 올라간다.');
    }
}
