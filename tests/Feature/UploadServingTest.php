<?php

namespace Tests\Feature;

use App\Controllers\Uploads;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * GET /uploads/<name> 서빙 — allowlist·캐시 헤더·조건부 요청.
 *
 * 실제 라우트를 call() 로 태운다(라우트 배선까지 함께 검증). 이 경로는 디렉터리를
 * 갈아끼울 seam 이 없으므로 파일명에 임의 접두사를 붙여 개발자의 실제 업로드와
 * 섞이지 않게 하고 tearDown 에서 지운다.
 *
 * 픽스처는 실제 이미지가 아니어도 된다 — Content-Type 이 확장자 매핑에서 나오므로
 * 내용을 볼 필요가 없다. 덕분에 gd 의 WebP 지원 같은 CI 의존이 생기지 않는다.
 *
 * @internal
 */
final class UploadServingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    // 전역 after 필터가 auth()->loggedIn() 을 호출하므로 Shield/Settings 까지 마이그레이션한다.
    protected $namespace = null;
    protected $refresh   = true;

    /** @var list<string> 정리할 픽스처 경로 */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 응답 서비스는 shared 라 앞 테스트가 붙인 헤더가 다음 테스트로 샌다.
        // 이 테스트는 Cache-Control 정확 일치를 단언하므로 누출되면 곧 위양성이 된다.
        Services::resetSingle('response');
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            @unlink($path);
        }

        $this->created = [];

        parent::tearDown();
    }

    /**
     * uploads 디렉터리에 픽스처를 만들고 파일명을 돌려준다.
     *
     * 내용은 임의 바이트다(위 클래스 주석 참조). 확장자는 호출부가 정한다.
     */
    private function makeUpload(string $ext = 'png'): string
    {
        $name = 'test-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $path = WRITEPATH . 'uploads/' . $name;

        file_put_contents($path, random_bytes(64));

        $this->created[] = $path;

        return $name;
    }

    /** 픽스처의 절대 경로. */
    private function pathOf(string $name): string
    {
        return WRITEPATH . 'uploads/' . $name;
    }

    public function testServesUploadedFile(): void
    {
        $name = $this->makeUpload('png');

        $result = $this->call('GET', 'uploads/' . $name);

        $result->assertStatus(200);
        $result->assertHeader('Content-Type', 'image/png');
        $this->assertSame(
            file_get_contents($this->pathOf($name)),
            // TestResponse 에는 getBody() 가 없어 __call 이 DOMParser::getBody() 로
            // 넘긴다 — 그건 saveHTML() 결과라 바이너리가 HTML 로 감싸진다.
            // 바디를 그대로 보려면 response() 를 거쳐야 한다.
            $result->response()->getBody(),
            '응답 바디가 파일 내용과 바이트 단위로 같아야 한다.'
        );
    }

    /**
     * 허용 확장자 4종이 알맞은 Content-Type 으로 나가고, 대문자 확장자도 통한다.
     *
     * 이 테스트가 이 변경의 가장 큰 실사고 방지망이다 — allowlist 오타나 대소문자
     * 비교 누락은 곧 "운영에서 모든 이미지가 404" 이고, 이 경로엔 지금 테스트가 없다.
     */
    public function testServesAllAllowedExtensions(): void
    {
        $expected = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'JPG'  => 'image/jpeg',
            'PNG'  => 'image/png',
        ];

        foreach ($expected as $ext => $type) {
            Services::resetSingle('response');

            $name   = $this->makeUpload($ext);
            $result = $this->call('GET', 'uploads/' . $name);

            $result->assertStatus(200);
            $result->assertHeader('Content-Type', $type);
        }
    }

    public function testRejectsExtensionsOutsideAllowlist(): void
    {
        $rejected = [];

        foreach (['html', 'php', 'svg', 'txt'] as $ext) {
            Services::resetSingle('response');

            $name = $this->makeUpload($ext);

            try {
                $this->call('GET', 'uploads/' . $name);
            } catch (PageNotFoundException) {
                $rejected[] = $ext;
            }
        }

        $this->assertSame(
            ['html', 'php', 'svg', 'txt'],
            $rejected,
            '목록 밖 확장자는 파일이 있어도 404 여야 한다.'
        );
    }

    public function testMissingFileIsNotFound(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->call('GET', 'uploads/does-not-exist.png');
    }

    /**
     * 경로 탈출 시도.
     *
     * 라우트 (:segment) 가 `[^/]+` 라 슬래시는 애초에 들어오지 못한다 —
     * 즉 이 테스트는 basename() 이 유일한 방어선임을 증명하지 못하고 2중 방어의
     * 존재를 기록한다. basename() 자체의 몫은 컨트롤러를 직접 불러 확인한다.
     */
    public function testTraversalAttemptIsNotFound(): void
    {
        $blocked = [];

        try {
            $this->call('GET', 'uploads/..%2F..%2Fenv');
        } catch (PageNotFoundException) {
            $blocked[] = '라우트';
        }

        // basename() 단독 검증: 슬래시가 든 이름을 직접 넘긴다.
        try {
            (new Uploads())->show('../../env');
        } catch (PageNotFoundException) {
            $blocked[] = 'basename';
        }

        $this->assertSame(
            ['라우트', 'basename'],
            $blocked,
            '라우트와 basename() 두 겹이 모두 경로 탈출을 막아야 한다.'
        );
    }

    /**
     * 캐시 정책은 정확 일치로 단언하고 no-store 부재까지 확인한다.
     *
     * setHeader('Cache-Control', ...) 를 쓰면 생성자의 noCache() 가 남긴 배열에
     * 덧붙어 `no-store, max-age=0, no-cache, public, max-age=..., immutable` 이
     * 된다(실측). no-store 가 앞에 있으면 캐싱이 통째로 무효인데도
     * assertStringContainsString('immutable', ...) 류는 통과한다 — 그래서
     * 정확 일치 + 음성 단언 둘 다 필요하다.
     */
    public function testSendsImmutableCacheControl(): void
    {
        $name = $this->makeUpload('png');

        $result = $this->call('GET', 'uploads/' . $name);

        $result->assertHeader('Cache-Control', 'public, max-age=31536000, immutable');
        $this->assertStringNotContainsString(
            'no-store',
            $result->response()->getHeaderLine('Cache-Control'),
            'no-store 가 남아 있으면 캐싱이 통째로 무효다.'
        );
    }

    public function testSendsStatBasedEtag(): void
    {
        $name = $this->makeUpload('png');
        $path = $this->pathOf($name);

        $result = $this->call('GET', 'uploads/' . $name);

        $this->assertSame(
            sprintf('"%x-%x"', filemtime($path), filesize($path)),
            $result->response()->getHeaderLine('ETag'),
            'ETag 는 filemtime 과 filesize 로 만든다 — 파일을 읽지 않고 304 를 판정하려는 것이다.'
        );
    }

    public function testSendsLastModified(): void
    {
        $name = $this->makeUpload('png');
        $path = $this->pathOf($name);

        $result = $this->call('GET', 'uploads/' . $name);

        $this->assertSame(
            gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT',
            $result->response()->getHeaderLine('Last-Modified'),
            'Last-Modified 가 filemtime 과 정확히 같아야 한다.'
        );
    }
}
