<?php

namespace Tests\Feature;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Shield\Test\AuthenticationTesting;

/**
 * 관리자 대시보드(/admin)에 대한 Feature 테스트.
 *
 * - 비로그인 사용자는 접근할 수 없다(로그인으로 리다이렉트).
 * - 일반 user 그룹은 접근할 수 없다(리다이렉트).
 * - admin 그룹은 200으로 대시보드를 본다.
 */
final class AdminDashboardTest extends CIUnitTestCase
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

        $user = new User([
            'username' => $username,
            'email'    => $email,
            'password' => 'secret-password-123',
        ]);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    private function makeAdmin(): User
    {
        $admin = $this->makeUser('admin', 'admin@example.com');
        $admin->addGroup('admin');

        return $admin;
    }

    public function testGuestCannotAccessDashboard(): void
    {
        $result = $this->call('GET', 'admin');

        $result->assertRedirect();
    }

    public function testNormalUserCannotAccessDashboard(): void
    {
        $user = $this->makeUser('member', 'member@example.com');

        $result = $this->actingAs($user)->call('GET', 'admin');

        $result->assertRedirect();
    }

    public function testAdminSeesDashboard(): void
    {
        $admin = $this->makeAdmin();

        $result = $this->actingAs($admin)->call('GET', 'admin');

        $result->assertStatus(200);
        $result->assertSee('대시보드');
    }
}
