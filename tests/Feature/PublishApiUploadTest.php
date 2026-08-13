<?php

namespace Tests\Feature;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use RuntimeException;
use Tests\Support\Libraries\FakeUploadStorage;

/**
 * 발행 API 의 대표 이미지 업로드(POST /api/uploads).
 *
 * 저장 동작만 UploadStorage seam 뒤로 보내고 여기서 가짜로 바꾼다 — 글 이미지(#103)·
 * 아바타(#95)와 같은 방식이다. UploadedFile::move() 가 CLI 에서 무조건 예외라서다.
 *
 * 썸네일 생성은 seam 밖이라 진짜 GD 로 돈다. 가짜가 copy() 로 실제 파일을 만들기
 * 때문인데, 덕분에 결과 크기(400x250)까지 검증할 수 있다.
 */
final class PublishApiUploadTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    private FakeUploadStorage $storage;

    /** @var list<string> 테스트가 만든 임시 파일 */
    private array $temps = [];

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        Services::resetSingle('session');
        Services::resetSingle('auth');
        Services::resetSingle('superglobals');

        $this->storage = new FakeUploadStorage(WRITEPATH . 'uploads');
        // 소문자로 주입해야 tearDown 의 resetSingle() 이 지운다.
        Services::injectMock('uploadstorage', $this->storage);
    }

    protected function tearDown(): void
    {
        foreach ([...$this->temps, ...$this->storage->stored] as $path) {
            foreach ([$path, dirname($path) . '/thumb_' . basename($path)] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }

        $_FILES = [];
        Services::resetSingle('superglobals');
        Services::resetSingle('uploadstorage');
        Services::resetSingle('image');

        parent::tearDown();
    }

    // ---------------------------------------------------------------- 도우미

    private function makeUser(string $username, string $email): User
    {
        $users = auth()->getProvider();
        $user  = new User(['username' => $username, 'email' => $email, 'password' => 'secret-password-123']);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    private function adminToken(): string
    {
        $admin = $this->makeUser('admin', 'admin@example.com');
        $admin->addGroup('admin');

        return $admin->generateAccessToken('test')->raw_token;
    }

    /** 크롭이 실제로 일어나는지 보려면 정사각형이 아니어야 한다. */
    private function makeTempJpeg(int $width = 640, int $height = 480): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 120, 160, 200));

        $path = tempnam(sys_get_temp_dir(), 'apicover');
        imagejpeg($image, $path);
        imagedestroy($image);

        $this->temps[] = $path;

        return $path;
    }

    private function makeTempText(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'apitext');
        file_put_contents($path, '이건 이미지가 아니다');

        $this->temps[] = $path;

        return $path;
    }

    /** @param int|null $size 실제 크기 대신 신고할 바이트 수(용량 초과 검증용) */
    private function attach(string $path, string $name, string $type, ?int $size = null): void
    {
        service('superglobals')->setFilesArray([
            'image' => [
                'name'     => $name,
                'type'     => $type,
                'size'     => $size ?? filesize($path),
                'tmp_name' => $path,
                'error'    => UPLOAD_ERR_OK,
            ],
        ]);
    }

    private function upload(?string $token)
    {
        $headers = $token === null ? [] : ['Authorization' => 'Bearer ' . $token];

        return $this->withHeaders($headers)->call('POST', 'api/uploads');
    }

    // ---------------------------------------------------------------- 인증

    public function testTokenIsRequired(): void
    {
        $this->attach($this->makeTempJpeg(), 'cover.jpg', 'image/jpeg');

        $this->upload(null)->assertStatus(401);

        $this->assertSame([], $this->storage->stored, '인증도 없이 파일이 저장됐다.');
    }

    public function testMemberTokenCannotUpload(): void
    {
        $token = $this->makeUser('member', 'member@example.com')->generateAccessToken('test')->raw_token;
        $this->attach($this->makeTempJpeg(), 'cover.jpg', 'image/jpeg');

        $this->upload($token)->assertStatus(403);

        $this->assertSame([], $this->storage->stored);
    }

    // ---------------------------------------------------------------- 저장

    public function testStoresImageAndGeneratesThumbnail(): void
    {
        $this->attach($this->makeTempJpeg(), 'cover.jpg', 'image/jpeg');

        $result = $this->upload($this->adminToken());
        $result->assertStatus(201);

        // TestResponse::getBody() 가 아니라 response()->getBody() 다. 앞의 것은
        // DOMParser 로 넘어가 본문을 <html><body> 로 감싸고 한글을 숫자 엔티티로
        // 바꿔 버려서 json_decode() 가 null 을 돌려준다.
        $body = json_decode($result->response()->getBody(), true);

        $this->assertArrayHasKey('name', $body);
        $this->assertArrayHasKey('url', $body);

        $original = WRITEPATH . 'uploads/' . $body['name'];
        $thumb    = WRITEPATH . 'uploads/thumb_' . $body['name'];

        $this->assertFileExists($original);
        $this->assertFileExists($thumb, '목록용 썸네일이 만들어지지 않았다.');

        // 400x250 로 잘려야 한다. 크기까지 보는 이유는, 썸네일 경로가 바뀌어
        // 원본을 그대로 복사하게 되면 목록이 조용히 느려지기 때문이다.
        [$width, $height] = getimagesize($thumb);
        $this->assertSame(400, $width);
        $this->assertSame(250, $height);
    }

    /**
     * 응답의 url 을 실제로 따라가면 방금 올린 원본이 나온다.
     *
     * 기대값을 site_url('uploads/' . $name) 으로 만들어 비교하는 방식은 쓰지 않는다.
     * 컨트롤러와 똑같은 함수로 같은 값을 두 번 만드는 셈이라, 그 함수가 통째로
     * 틀려도 양쪽이 함께 틀려 초록불이 된다([[ci4blog-siteurl-macos-bug]] 가 그 예다).
     *
     * 대신 응답이 준 url 에서 경로를 뽑아 그대로 GET 한다. 여기서 'uploads/{name}' 을
     * 손으로 조립해 GET 하면 url 필드가 엉뚱한 값이어도 통과하는 테스트가 된다 —
     * 이 테스트가 지키려는 것은 응답 url 과 서빙 라우트가 맞물린다는 사실이다.
     *
     * 헤더·캐시·allowlist 같은 서빙 자체의 계약은 UploadServingTest 가 본다.
     */
    public function testReturnedUrlIsServable(): void
    {
        $this->attach($this->makeTempJpeg(), 'cover.jpg', 'image/jpeg');

        $body = json_decode($this->upload($this->adminToken())->response()->getBody(), true);

        $this->assertStringStartsWith(base_url(), $body['url'], 'url 은 이 사이트의 절대 URL 이어야 한다.');

        $route = $this->routeOf($body['url']);
        $this->assertSame('uploads/' . $body['name'], $route, 'url 이 서빙 라우트와 어긋난다.');

        $served = $this->call('GET', $route);
        $served->assertStatus(200);

        // 원본이어야 한다. 썸네일(400x250)을 가리키게 되면 여기서 갈린다.
        $this->assertSame(
            file_get_contents(WRITEPATH . 'uploads/' . $body['name']),
            $served->response()->getBody(),
            'url 이 방금 올린 원본을 돌려주지 않는다.'
        );
    }

    /** 절대 URL 에서 라우팅 경로만 뽑는다. app.indexPage 가 붙어 있으면 뗀다. */
    private function routeOf(string $url): string
    {
        $route     = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        $indexPage = config('App')->indexPage;

        return $indexPage !== '' && str_starts_with($route, $indexPage . '/')
            ? substr($route, strlen($indexPage) + 1)
            : $route;
    }

    // ---------------------------------------------------------------- 검증

    public function testRejectsNonImage(): void
    {
        $this->attach($this->makeTempText(), 'notes.txt', 'text/plain');

        $this->upload($this->adminToken())->assertStatus(400);

        $this->assertSame([], $this->storage->stored);
    }

    /**
     * 관리자 화면과 같은 2MB 제한을 쓴다.
     *
     * 두 경로가 갈라지면 웹에서 거부된 파일이 API 로는 들어가는 구멍이 생긴다.
     */
    public function testRejectsOversizedImage(): void
    {
        $this->attach($this->makeTempJpeg(), 'huge.jpg', 'image/jpeg', 3 * 1024 * 1024);

        $this->upload($this->adminToken())->assertStatus(400);

        $this->assertSame([], $this->storage->stored);
    }

    public function testRejectsMissingFile(): void
    {
        $this->upload($this->adminToken())->assertStatus(400);
    }

    /**
     * 썸네일 생성이 터져도 읽을 수 있는 JSON 이 나간다.
     *
     * 검증을 통과한 파일이라도 GD 는 메모리 한계나 손상된 헤더에서 예외를 던진다.
     * 그대로 두면 프레임워크 예외 덤프가 나가는데, 이 API 를 부르는 쪽은 브라우저가
     * 아니라 발행 스크립트라 HTML 덤프를 받으면 원인을 알 수 없다.
     *
     * 원본은 지우지 않는다. 썸네일만 실패한 상황에서 올린 파일까지 되돌리면
     * 사용자는 아무것도 손에 쥐지 못한 채 다시 올려야 한다 — 목록 썸네일은
     * 나중에 다시 만들 수 있지만 업로드는 그렇지 않다.
     */
    public function testThumbnailFailureStillReturnsJson(): void
    {
        $this->attach($this->makeTempJpeg(), 'cover.jpg', 'image/jpeg');

        Services::injectMock('image', new class () {
            public function withFile(string $path): static
            {
                throw new RuntimeException('GD 가 이미지를 열지 못했다');
            }
        });

        $result = $this->upload($this->adminToken());

        $result->assertStatus(500);

        $body = json_decode($result->response()->getBody(), true);
        $this->assertIsArray($body, '예외 덤프가 아니라 JSON 이어야 한다.');
        $this->assertArrayHasKey('messages', $body);

        $this->assertCount(1, $this->storage->stored, '원본은 저장된 상태로 남아야 한다.');
        $this->assertFileExists($this->storage->stored[0]);
    }
}
