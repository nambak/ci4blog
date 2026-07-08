<?php

namespace Tests\Feature;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * 프로필 수정(/profile) Feature 테스트.
 */
final class ProfileTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected $namespace = null;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        \Config\Services::resetSingle('session');
        \Config\Services::resetSingle('auth');
    }

    private function makeUser(string $username, string $email): User
    {
        $users = auth()->getProvider();
        $user  = new User(['username' => $username, 'email' => $email, 'password' => 'secret-password-123']);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    public function testProviderPersistsAvatarColumn(): void
    {
        $user = $this->makeUser('nambak', 'nambak@example.com');

        $users        = auth()->getProvider();
        $user->avatar = 'avatar_test.png';
        $users->save($user);

        $reloaded = $users->findById($user->id);
        $this->assertSame('avatar_test.png', $reloaded->avatar);
    }

    public function testGuestCannotAccessProfile(): void
    {
        $result = $this->call('GET', 'profile');

        $result->assertRedirect();
    }

    public function testUserSeesOwnUsernameOnForm(): void
    {
        $user   = $this->makeUser('nambak', 'nambak@example.com');
        $result = $this->actingAs($user)->call('GET', 'profile');

        $result->assertOK();
        $result->assertSee('nambak');
        $result->assertSee('프로필', 'html');
    }

    public function testUpdatesUsername(): void
    {
        $user   = $this->makeUser('oldname', 'me@example.com');
        $result = $this->actingAs($user)->call('POST', 'profile', [
            'username' => 'newname',
        ]);

        $result->assertRedirect();
        $reloaded = auth()->getProvider()->findById($user->id);
        $this->assertSame('newname', $reloaded->username);
    }

    public function testRejectsDuplicateUsername(): void
    {
        $this->makeUser('taken', 'taken@example.com');
        $user = $this->makeUser('me', 'me@example.com');

        $result = $this->actingAs($user)->call('POST', 'profile', [
            'username' => 'taken',
        ]);

        $result->assertRedirect();
        $reloaded = auth()->getProvider()->findById($user->id);
        $this->assertSame('me', $reloaded->username); // 변경 안 됨
    }

    public function testRejectsEmptyUsername(): void
    {
        $user   = $this->makeUser('me', 'me@example.com');
        $result = $this->actingAs($user)->call('POST', 'profile', [
            'username' => '',
        ]);

        $result->assertRedirect();
        $reloaded = auth()->getProvider()->findById($user->id);
        $this->assertSame('me', $reloaded->username);
    }

    public function testChangesPasswordWithCorrectCurrent(): void
    {
        $user = $this->makeUser('me', 'me@example.com'); // 비번: secret-password-123

        $result = $this->actingAs($user)->call('POST', 'profile', [
            'username'             => 'me',
            'current_password'     => 'secret-password-123',
            'new_password'         => 'brand-new-pass-456',
            'new_password_confirm' => 'brand-new-pass-456',
        ]);

        $result->assertRedirect();
        $check = auth('session')->check(['email' => 'me@example.com', 'password' => 'brand-new-pass-456']);
        $this->assertTrue($check->isOK());
    }

    public function testRejectsWrongCurrentPassword(): void
    {
        $user = $this->makeUser('me', 'me@example.com');

        $result = $this->actingAs($user)->call('POST', 'profile', [
            'username'             => 'me',
            'current_password'     => 'WRONG-current',
            'new_password'         => 'brand-new-pass-456',
            'new_password_confirm' => 'brand-new-pass-456',
        ]);

        $result->assertRedirect();
        // 기존 비번은 그대로 유효
        $check = auth('session')->check(['email' => 'me@example.com', 'password' => 'secret-password-123']);
        $this->assertTrue($check->isOK());
    }

    public function testBlankNewPasswordLeavesPasswordUnchanged(): void
    {
        $user = $this->makeUser('me', 'me@example.com');

        $result = $this->actingAs($user)->call('POST', 'profile', [
            'username'     => 'renamed',
            'new_password' => '',
        ]);

        $result->assertRedirect();
        $check = auth('session')->check(['email' => 'me@example.com', 'password' => 'secret-password-123']);
        $this->assertTrue($check->isOK()); // 비번 유지
        $reloaded = auth()->getProvider()->findById($user->id);
        $this->assertSame('renamed', $reloaded->username); // 사용자명은 바뀜
    }
}
