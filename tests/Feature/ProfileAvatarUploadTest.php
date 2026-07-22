<?php

namespace Tests\Feature;

use App\Libraries\UploadStorage;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;
use Tests\Support\Libraries\FakeUploadStorage;
use Tests\Support\Traits\WithCsrf;

/**
 * 아바타 실제 업로드(/profile) Feature 테스트.
 *
 * CLI 에서는 is_uploaded_file() 이 항상 false 라 UploadedFile::move() 가 예외를 던진다.
 * 그래서 저장 동작만 UploadStorage seam 뒤로 보내고 여기서 가짜로 바꾼다.
 * 가짜도 copy() 로 실제 파일을 만들기 때문에, 파일 존재를 전제로 하는 뒷단
 * 로직(옛 파일 삭제 등)이 진짜로 동작하는지까지 검증된다.
 *
 * 덮지 못하는 것: move_uploaded_file() 호출 한 줄(프레임워크 코드).
 */
final class ProfileAvatarUploadTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use WithCsrf;
    use AuthenticationTesting;

    protected $namespace = null;
    protected $refresh   = true;

    private FakeUploadStorage $storage;

    /** @var list<string> 테스트가 만든 임시 파일 경로 */
    private array $temps = [];

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        Services::resetSingle('session');
        Services::resetSingle('auth');
        Services::resetSingle('superglobals');

        $this->storage = new FakeUploadStorage(WRITEPATH . 'uploads');

        // 서비스 이름을 소문자로 주입한다. injectMock() 은 $instances 에 준 이름 그대로,
        // $mocks 에는 소문자로 넣는데 resetSingle() 은 소문자 키만 지운다. camelCase 로
        // 주입하면 $instances['uploadStorage'] 가 남아 다음 테스트 클래스까지 새어 나가고,
        // service() 는 파라미터가 없을 때 그 $instances 를 그대로 읽는다.
        Services::injectMock('uploadstorage', $this->storage);
    }

    protected function tearDown(): void
    {
        foreach ([...$this->temps, ...$this->storage->stored] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        // setFilesArray() 는 $_FILES 전역까지 덮어쓴다(Superglobals.php:408).
        // 비워 두지 않으면 뒤따르는 테스트가 이미 지워진 임시 파일을 업로드로 인식한다.
        $_FILES = [];
        Services::resetSingle('superglobals');

        // CIUnitTestCase 는 서비스를 자동으로 되돌리지 않는다($tearDownMethods = []).
        // 치우지 않으면 가짜 저장기가 다른 테스트 클래스까지 살아남는다.
        Services::resetSingle('uploadstorage');

        parent::tearDown();
    }

    private function makeUser(): User
    {
        $users = auth()->getProvider();
        $user  = new User([
            'username' => 'me',
            'email'    => 'me@example.com',
            'password' => 'secret-password-123',
        ]);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    /** 1x1 PNG 임시 파일을 만든다(업로드된 파일 자리). */
    private function makeTempPng(): string
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        );

        // tempnam() 이 만든 파일을 그대로 쓴다. 뒤에 확장자를 붙이면 원본이
        // 추적되지 않은 채 /tmp 에 남는다. 업로드 파일명은 attach() 의 $name 이 정한다.
        $path = tempnam(sys_get_temp_dir(), 'avatar');
        file_put_contents($path, $png);
        $this->temps[] = $path;

        return $path;
    }

    /**
     * 업로드된 파일을 $_FILES 자리에 놓는다.
     *
     * @param int|null $size 실제 크기 대신 신고할 바이트 수(용량 초과 검증용)
     */
    private function attach(string $path, string $name, string $type, ?int $size = null): void
    {
        service('superglobals')->setFilesArray([
            'avatar' => [
                'name'     => $name,
                'type'     => $type,
                'size'     => $size ?? filesize($path),
                'tmp_name' => $path,
                'error'    => UPLOAD_ERR_OK,
            ],
        ]);
    }

    /**
     * 가짜 저장기가 tearDown 으로 확실히 치워지는 이름으로 등록됐는지 지킨다.
     *
     * camelCase 로 주입하면 resetSingle() 이 못 지워 다음 테스트 클래스까지 새어 나간다.
     * 그 사고는 조용해서(다른 테스트가 업로드를 안 하면 아무도 안 깨진다) 방지망이 필요하다.
     */
    public function testFakeStorageIsRegisteredUnderAResettableKey(): void
    {
        Services::resetSingle('uploadstorage');

        $this->assertSame(
            UploadStorage::class,
            service('uploadStorage')::class,
            'resetSingle() 뒤에는 진짜 저장기가 돌아와야 한다'
        );

        Services::injectMock('uploadstorage', $this->storage);
    }

    public function testStoresUploadedAvatarAndPersistsFilename(): void
    {
        $user = $this->makeUser();
        $this->attach($this->makeTempPng(), 'me.png', 'image/png');

        $result = $this->actingAs($user)->call('POST', 'profile', ['username' => 'me']);

        $result->assertRedirect();

        $avatar = auth()->getProvider()->findById($user->id)->avatar;
        $this->assertNotNull($avatar, '업로드 후 avatar 컬럼이 채워져야 한다');
        $this->assertStringEndsWith('.png', $avatar);
        $this->assertFileExists(WRITEPATH . 'uploads/' . $avatar, '저장된 파일이 실제로 있어야 한다');
    }

    public function testReplacingAvatarRemovesPreviousFile(): void
    {
        $user  = $this->makeUser();
        $users = auth()->getProvider();

        // 이미 아바타가 있는 상태를 만든다(컬럼 + 실제 파일).
        $oldName = 'old_avatar_' . bin2hex(random_bytes(4)) . '.png';
        $oldPath = WRITEPATH . 'uploads/' . $oldName;
        copy($this->makeTempPng(), $oldPath);
        $this->temps[]  = $oldPath;
        $user->avatar   = $oldName;
        $users->save($user);

        $this->attach($this->makeTempPng(), 'new.png', 'image/png');

        $result = $this->actingAs($user)->call('POST', 'profile', ['username' => 'me']);

        $result->assertRedirect();

        $avatar = $users->findById($user->id)->avatar;
        $this->assertNotSame($oldName, $avatar, '컬럼이 새 파일명으로 바뀌어야 한다');
        $this->assertFileExists(WRITEPATH . 'uploads/' . $avatar, '새 파일이 있어야 한다');
        $this->assertFileDoesNotExist($oldPath, '옛 파일은 지워져야 한다');
    }

    public function testRejectsNonImageDisguisedAsPng(): void
    {
        $user  = $this->makeUser();
        $users = auth()->getProvider();
        $user->avatar = 'keep_me.png';
        $users->save($user);

        // 내용은 텍스트인데 확장자·신고 타입만 이미지인 파일.
        $path = tempnam(sys_get_temp_dir(), 'fake');
        file_put_contents($path, "not an image\n");
        $this->temps[] = $path;
        $this->attach($path, 'evil.png', 'image/png');

        $result = $this->actingAs($user)->call('POST', 'profile', ['username' => 'renamed']);

        $result->assertRedirect();
        $this->assertSame([], $this->storage->stored, '검증 실패 시 저장을 시도하면 안 된다');
        $this->assertSame('keep_me.png', $users->findById($user->id)->avatar, '기존 아바타가 유지돼야 한다');
        $this->assertSame('me', $users->findById($user->id)->username, '검증 실패 시 사용자명도 저장되면 안 된다');
    }

    public function testRejectsFileOverSizeLimit(): void
    {
        $user  = $this->makeUser();
        $users = auth()->getProvider();
        $user->avatar = 'keep_me.png';
        $users->save($user);

        // 규칙은 max_size[avatar,2048](KB). 실제 큰 파일 대신 신고 크기를 3MB 로 준다
        // — 실제 업로드에서도 PHP 가 $_FILES['size'] 에 전송 크기를 넣고, getSize() 가 그 값을 쓴다.
        $this->attach($this->makeTempPng(), 'big.png', 'image/png', 3 * 1024 * 1024);

        $result = $this->actingAs($user)->call('POST', 'profile', ['username' => 'renamed']);

        $result->assertRedirect();
        $this->assertSame([], $this->storage->stored, '용량 초과 시 저장을 시도하면 안 된다');
        $this->assertSame('keep_me.png', $users->findById($user->id)->avatar, '기존 아바타가 유지돼야 한다');
    }
}
