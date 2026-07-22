<?php

namespace Tests\Feature;

use App\Models\PostModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;
use Tests\Support\Libraries\FakeUploadStorage;
use Tests\Support\Traits\WithCsrf;

/**
 * 글 대표 이미지 업로드(#103).
 *
 * 아바타(#95)와 같은 방식이다 — UploadedFile::move() 가 CLI 에서 무조건 예외라
 * 저장 동작만 UploadStorage seam 뒤로 보내고 여기서 가짜로 바꾼다.
 *
 * 썸네일 생성은 seam 밖에 있다. 가짜가 copy() 로 진짜 파일을 만들기 때문에
 * service('image') 가 실제 GD 로 돌고, 그 결과(400x250)까지 검증할 수 있다.
 * 썸네일까지 가짜 안으로 넣었다면 이 검증은 통째로 사라졌을 것이다.
 *
 * 덮지 못하는 것: move_uploaded_file() 호출 한 줄(프레임워크 코드).
 */
final class PostImageUploadTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use WithCsrf;
    use \CodeIgniter\Shield\Test\AuthenticationTesting;

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
        // 소문자로 주입해야 tearDown 의 resetSingle() 이 지운다(#95 에서 데인 자리).
        Services::injectMock('uploadstorage', $this->storage);
    }

    protected function tearDown(): void
    {
        // 원본과 썸네일을 함께 치운다.
        foreach ([...$this->temps, ...$this->storage->stored] as $path) {
            foreach ([$path, dirname($path) . '/thumb_' . basename($path)] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        }

        $_FILES = [];
        Services::resetSingle('superglobals');
        Services::resetSingle('uploadstorage');

        parent::tearDown();
    }

    private function makeUser(): User
    {
        $users = auth()->getProvider();
        $user  = new User(['username' => 'author', 'email' => 'author@example.com', 'password' => 'secret-password-123']);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    /**
     * 크롭이 실제로 일어나는지 보려면 정사각형이 아니어야 한다.
     * 640x480 을 400x250 으로 fit 하면 위아래가 잘린다.
     */
    private function makeTempJpeg(int $width = 640, int $height = 480): string
    {
        $im = imagecreatetruecolor($width, $height);
        imagefilledrectangle($im, 0, 0, $width, $height, imagecolorallocate($im, 120, 160, 200));

        $path = tempnam(sys_get_temp_dir(), 'cover');
        imagejpeg($im, $path);
        imagedestroy($im);

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

    private function uploadPath(string $name): string
    {
        return WRITEPATH . 'uploads/' . $name;
    }

    public function testStoresCoverImageAndGeneratesThumbnail(): void
    {
        $user = $this->makeUser();
        $this->attach($this->makeTempJpeg(), 'cover.jpg', 'image/jpeg');

        $result = $this->actingAs($user)->call('POST', 'posts', [
            'title' => '대표 이미지가 있는 글',
            'body'  => '본문',
        ]);

        $result->assertRedirect();

        $image = model(PostModel::class)->where('title', '대표 이미지가 있는 글')->first()->image;
        $this->assertNotNull($image, '업로드하면 image 컬럼이 채워져야 한다');

        $this->assertFileExists($this->uploadPath($image), '원본이 저장돼야 한다');

        $thumb = $this->uploadPath('thumb_' . $image);
        $this->assertFileExists($thumb, '목록용 썸네일이 만들어져야 한다');

        // 존재만 보면 크롭이 깨져도 통과한다.
        [$w, $h] = getimagesize($thumb);
        $this->assertSame(400, $w, '썸네일 너비');
        $this->assertSame(250, $h, '썸네일 높이');
    }

    public function testRejectsNonImageDisguisedAsJpeg(): void
    {
        $user = $this->makeUser();

        $path = tempnam(sys_get_temp_dir(), 'fake');
        file_put_contents($path, "not an image\n");
        $this->temps[] = $path;
        $this->attach($path, 'evil.jpg', 'image/jpeg');

        $result = $this->actingAs($user)->call('POST', 'posts', [
            'title' => '위장 파일 글',
            'body'  => '본문',
        ]);

        $result->assertRedirect();
        $this->assertSame([], $this->storage->stored, '검증 실패 시 저장을 시도하면 안 된다');
        $this->dontSeeInDatabase('posts', ['title' => '위장 파일 글']);
    }

    public function testRejectsImageOverSizeLimit(): void
    {
        $user = $this->makeUser();
        // 규칙은 max_size[image,2048](KB). 실제 큰 파일 대신 신고 크기를 3MB 로 준다 —
        // 실제 업로드에서도 PHP 가 $_FILES['size'] 에 전송 크기를 넣고 getSize() 가 그 값을 쓴다.
        $this->attach($this->makeTempJpeg(), 'big.jpg', 'image/jpeg', 3 * 1024 * 1024);

        $result = $this->actingAs($user)->call('POST', 'posts', [
            'title' => '용량 초과 글',
            'body'  => '본문',
        ]);

        $result->assertRedirect();
        $this->assertSame([], $this->storage->stored, '용량 초과 시 저장을 시도하면 안 된다');
        $this->dontSeeInDatabase('posts', ['title' => '용량 초과 글']);
    }

    public function testReplacingCoverImageRemovesPreviousFiles(): void
    {
        $user = $this->makeUser();

        $this->attach($this->makeTempJpeg(), 'first.jpg', 'image/jpeg');
        $this->actingAs($user)->call('POST', 'posts', ['title' => '교체 대상 글', 'body' => '본문']);

        $posts = model(PostModel::class);
        $post  = $posts->where('title', '교체 대상 글')->first();
        $old   = $post->image;
        $this->assertFileExists($this->uploadPath($old));

        $this->attach($this->makeTempJpeg(), 'second.jpg', 'image/jpeg');
        $this->actingAs($user)->call('POST', "posts/{$post->id}", ['title' => '교체 대상 글', 'body' => '본문 수정']);

        $new = $posts->find($post->id)->image;
        $this->assertNotSame($old, $new, 'image 컬럼이 새 파일명으로 바뀌어야 한다');
        $this->assertFileExists($this->uploadPath($new));
        $this->assertFileDoesNotExist($this->uploadPath($old), '옛 원본은 지워져야 한다');
        $this->assertFileDoesNotExist($this->uploadPath('thumb_' . $old), '옛 썸네일도 지워져야 한다');
    }

    public function testDeletingPostRemovesImageAndThumbnail(): void
    {
        $user = $this->makeUser();
        $this->attach($this->makeTempJpeg(), 'cover.jpg', 'image/jpeg');
        $this->actingAs($user)->call('POST', 'posts', ['title' => '삭제 대상 글', 'body' => '본문']);

        $post  = model(PostModel::class)->where('title', '삭제 대상 글')->first();
        $image = $post->image;
        $this->assertFileExists($this->uploadPath($image));
        $this->assertFileExists($this->uploadPath('thumb_' . $image));

        $this->actingAs($user)->call('POST', "posts/{$post->id}/delete")->assertRedirect();

        $this->assertFileDoesNotExist($this->uploadPath($image), '글을 지우면 원본도 지워져야 한다');
        $this->assertFileDoesNotExist($this->uploadPath('thumb_' . $image), '썸네일도 함께 지워져야 한다');
    }
}
