<?php

namespace Tests\Feature;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Shield\Test\AuthenticationTesting;

/**
 * 글 저장(POST /posts)에 대한 Feature 테스트.
 *
 * - 비로그인 사용자는 session 필터에 막혀 저장할 수 없다.
 * - 로그인 사용자는 유효한 입력이면 글이 저장된다.
 * - 검증에 실패하면 저장되지 않고 폼으로 되돌아간다.
 */
final class PostStoreTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected $namespace = null;
    protected $refresh   = true;

    /**
     * 테스트용 사용자 한 명을 만들어 돌려준다.
     */
    private function makeUser(): User
    {
        $users = auth()->getProvider();

        $user = new User([
            'username' => 'writer',
            'email'    => 'writer@example.com',
            'password' => 'secret-password-123',
        ]);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    public function testGuestCannotStorePost(): void
    {
        // 로그인하지 않으면 session 필터가 막아 글이 저장되지 않는다.
        $result = $this->call('POST', 'posts', [
            'title' => '게스트 글',
            'body'  => '저장되면 안 된다.',
        ]);

        $result->assertRedirect();
        $this->dontSeeInDatabase('posts', ['title' => '게스트 글']);
    }

    public function testLoggedInUserCanStorePost(): void
    {
        $result = $this->actingAs($this->makeUser())
            ->call('POST', 'posts', [
                'title' => '새 글 제목',
                'body'  => '새 글 본문입니다.',
            ]);

        // 저장 후 목록으로 리다이렉트한다.
        $result->assertRedirect();
        $this->seeInDatabase('posts', [
            'title' => '새 글 제목',
            'body'  => '새 글 본문입니다.',
        ]);
    }

    public function testValidationFailsWithEmptyTitle(): void
    {
        $result = $this->actingAs($this->makeUser())
            ->call('POST', 'posts', [
                'title' => '',
                'body'  => '제목 없는 본문',
            ]);

        // 검증 실패 시 저장하지 않고 폼으로 되돌린다.
        $result->assertRedirect();
        $this->dontSeeInDatabase('posts', ['body' => '제목 없는 본문']);
    }
}
