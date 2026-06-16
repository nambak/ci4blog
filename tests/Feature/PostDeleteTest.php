<?php

namespace Tests\Feature;

use App\Models\PostModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Shield\Test\AuthenticationTesting;

/**
 * 글 삭제와 작성자 권한에 대한 Feature 테스트.
 *
 * - 비로그인 사용자는 삭제할 수 없다.
 * - 작성자 본인은 자기 글을 삭제할 수 있다.
 * - 작성자가 아닌(관리자도 아닌) 사용자는 삭제할 수 없다(403).
 * - 관리자는 남의 글도 삭제할 수 있다.
 * - 삭제 버튼은 권한이 있을 때만 상세 화면에 보인다.
 */
final class PostDeleteTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected $namespace = null;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        // 앞선 테스트의 로그인 세션이 새 나가지 않도록 비운다.
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

    private function makePost(int $userId): int
    {
        $model = model(PostModel::class);
        // 제목/본문에 '삭제'가 들어가면 버튼 노출 검사와 헷갈리므로 피한다.
        // slug 는 ep17부터 PostModel 이 제목으로 자동 생성한다.
        $model->insert([
            'user_id' => $userId,
            'title'   => '권한 테스트 글',
            'body'    => '권한 테스트 본문',
        ]);

        return $model->getInsertID();
    }

    public function testGuestCannotDeletePost(): void
    {
        $id = $this->makePost(1);

        $result = $this->call('POST', "posts/{$id}/delete");

        $result->assertRedirect();
        $this->seeInDatabase('posts', ['id' => $id]);
    }

    public function testAuthorCanDeleteOwnPost(): void
    {
        $author = $this->makeUser('author', 'author@example.com');
        $id     = $this->makePost($author->id);

        $result = $this->actingAs($author)->call('POST', "posts/{$id}/delete");

        $result->assertRedirect();
        $this->dontSeeInDatabase('posts', ['id' => $id]);
    }

    public function testNonAuthorCannotDeletePost(): void
    {
        $author = $this->makeUser('author', 'author@example.com');
        $id     = $this->makePost($author->id);
        $other  = $this->makeUser('other', 'other@example.com');

        $result = $this->actingAs($other)->call('POST', "posts/{$id}/delete");

        $result->assertStatus(403);
        $this->seeInDatabase('posts', ['id' => $id]);
    }

    public function testAdminCanDeleteAnyPost(): void
    {
        $author = $this->makeUser('author', 'author@example.com');
        $id     = $this->makePost($author->id);

        $admin = $this->makeUser('admin', 'admin@example.com');
        $admin->addGroup('admin');

        $result = $this->actingAs($admin)->call('POST', "posts/{$id}/delete");

        $result->assertRedirect();
        $this->dontSeeInDatabase('posts', ['id' => $id]);
    }

    public function testDeleteButtonVisibleToAuthor(): void
    {
        $author = $this->makeUser('author', 'author@example.com');
        $id     = $this->makePost($author->id);
        $slug   = model(PostModel::class)->find($id)->slug;

        $result = $this->actingAs($author)->call('GET', 'posts/' . $slug);

        $result->assertSee('삭제');
    }

    public function testDeleteButtonHiddenFromNonAuthor(): void
    {
        $author = $this->makeUser('author', 'author@example.com');
        $id     = $this->makePost($author->id);
        $other  = $this->makeUser('other', 'other@example.com');
        $slug   = model(PostModel::class)->find($id)->slug;

        $result = $this->actingAs($other)->call('GET', 'posts/' . $slug);

        $result->assertDontSee('삭제');
    }
}
