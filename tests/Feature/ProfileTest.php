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

    public function testDeleteAvatarClearsColumn(): void
    {
        $user         = $this->makeUser('me', 'me@example.com');
        $users        = auth()->getProvider();
        $user->avatar = 'nonexistent.png'; // 파일 없어도 컬럼만 비우면 됨
        $users->save($user);

        $result = $this->actingAs($user)->call('POST', 'profile/avatar/delete');

        $result->assertRedirect();
        $reloaded = $users->findById($user->id);
        $this->assertNull($reloaded->avatar);
    }

    public function testUpdateWithoutFileKeepsAvatar(): void
    {
        $user         = $this->makeUser('me', 'me@example.com');
        $users        = auth()->getProvider();
        $user->avatar = 'keep_me.png';
        $users->save($user);

        $result = $this->actingAs($user)->call('POST', 'profile', ['username' => 'renamed']);

        $result->assertRedirect();
        $reloaded = $users->findById($user->id);
        $this->assertSame('keep_me.png', $reloaded->avatar); // 파일 미첨부 시 유지
    }

    public function testHeaderShowsProfileLinkWhenLoggedIn(): void
    {
        $user   = $this->makeUser('me', 'me@example.com');
        $result = $this->actingAs($user)->call('GET', '/');

        $result->assertOK();
        $result->assertSee('프로필', 'html');
        $result->assertSeeElement('a[href=' . site_url('profile') . ']');
    }

    public function testPostShowRendersAuthorAvatar(): void
    {
        $user         = $this->makeUser('writer', 'writer@example.com');
        $users        = auth()->getProvider();
        $user->avatar = 'writer_pic.png';
        $users->save($user);

        $posts = model(\App\Models\PostModel::class);
        $posts->insert(['user_id' => $user->id, 'category_id' => null, 'title' => '아바타글', 'body' => '본문']);
        $slug = $posts->find($posts->getInsertID())->slug;

        $result = $this->call('GET', 'posts/' . $slug);

        $result->assertOK();
        // assertSee(text, selector)의 selector 는 CSS 선택자로, 매치된 요소의 텍스트 내용만 검사한다.
        // 아바타 경로는 <img src="..."> 속성값이라 텍스트 노드가 아니므로 selector 없이 전체 HTML 에서 찾는다.
        $result->assertSee('writer_pic.png'); // 바이라인 아바타 이미지 경로
    }

    public function testCommentRendersAuthorAvatar(): void
    {
        $user         = $this->makeUser('commenter', 'c@example.com');
        $users        = auth()->getProvider();
        $user->avatar = 'commenter_pic.png';
        $users->save($user);

        $posts = model(\App\Models\PostModel::class);
        $posts->insert(['user_id' => $user->id, 'category_id' => null, 'title' => '댓글글', 'body' => '본문']);
        $postId = $posts->getInsertID();
        $slug   = $posts->find($postId)->slug;

        model(\App\Models\CommentModel::class)->insert([
            'post_id' => $postId, 'user_id' => $user->id, 'body' => '댓글내용',
        ]);

        $result = $this->call('GET', 'posts/' . $slug);

        $result->assertOK();
        $result->assertSee('commenter_pic.png'); // 댓글 아바타 이미지 경로(속성값이라 selector 없이 검사)
    }
}
