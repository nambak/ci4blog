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
}
