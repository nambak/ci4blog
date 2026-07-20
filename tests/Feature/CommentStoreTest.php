<?php

namespace Tests\Feature;

use App\Entities\Post;
use App\Models\PostModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use Tests\Support\Traits\WithCsrf;

/**
 * 댓글 저장(POST /posts/{id}/comments)에 대한 Feature 테스트.
 *
 * - 비로그인 사용자는 session 필터에 막혀 댓글을 달 수 없다.
 * - 로그인 사용자는 유효한 입력이면 댓글이 저장된다.
 * - 내용이 비면 저장되지 않는다.
 */
final class CommentStoreTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use WithCsrf;
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

    private function makeUser(): User
    {
        $users = auth()->getProvider();

        $user = new User([
            'username' => 'commenter',
            'email'    => 'commenter@example.com',
            'password' => 'secret-password-123',
        ]);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    private function makePost(int $userId): int
    {
        $model = model(PostModel::class);
        $model->insert([
            'user_id' => $userId,
            'title'   => '댓글 달 글',
            'body'    => '본문',
        ]);

        return $model->getInsertID();
    }

    public function testGuestCannotStoreComment(): void
    {
        $postId = $this->makePost(1);

        $result = $this->call('POST', "posts/{$postId}/comments", [
            'body' => '게스트 댓글',
        ]);

        $result->assertRedirect();
        $this->dontSeeInDatabase('comments', ['body' => '게스트 댓글']);
    }

    public function testLoggedInUserCanStoreComment(): void
    {
        $user   = $this->makeUser();
        $postId = $this->makePost($user->id);

        $result = $this->actingAs($user)->call('POST', "posts/{$postId}/comments", [
            'body' => '좋은 글이네요!',
        ]);

        $result->assertRedirect();
        $this->seeInDatabase('comments', [
            'post_id' => $postId,
            'user_id' => $user->id,
            'body'    => '좋은 글이네요!',
        ]);
    }

    public function testValidationFailsWithEmptyBody(): void
    {
        $user   = $this->makeUser();
        $postId = $this->makePost($user->id);

        $result = $this->actingAs($user)->call('POST', "posts/{$postId}/comments", [
            'body' => '',
        ]);

        $result->assertRedirect();
        $this->dontSeeInDatabase('comments', ['post_id' => $postId]);
    }

    /**
     * 비발행 글(초안·비공개)은 상세가 404 로 막힌다. 댓글 저장도 같은 규칙을 따라야
     * 한다 — 그러지 않으면 남의 초안에 댓글을 달 수 있고, 리다이렉트 Location 헤더로
     * 비발행 글의 슬러그가 새어 나간다.
     */
    public function testCannotCommentOnAnotherAuthorsDraft(): void
    {
        $owner = $this->makeUser();

        $posts = model(PostModel::class);
        $posts->insert([
            'user_id' => $owner->id,
            'title'   => '남의 초안',
            'body'    => '본문',
            'status'  => Post::STATUS_DRAFT,
        ]);
        $postId = $posts->getInsertID();

        $users  = auth()->getProvider();
        $other  = new User(['username' => 'intruder', 'email' => 'intruder@example.com', 'password' => 'secret-password-123']);
        $users->save($other);
        $other = $users->findById($users->getInsertID());

        $this->expectException(PageNotFoundException::class);

        try {
            $this->actingAs($other)->call('POST', "posts/{$postId}/comments", ['body' => '몰래 다는 댓글']);
        } finally {
            // 예외가 나든 안 나든 댓글은 저장되지 않아야 한다.
            $this->dontSeeInDatabase('comments', ['post_id' => $postId]);
        }
    }

    public function testOwnerCanCommentOnOwnDraft(): void
    {
        $owner = $this->makeUser();

        $posts = model(PostModel::class);
        $posts->insert([
            'user_id' => $owner->id,
            'title'   => '내 초안',
            'body'    => '본문',
            'status'  => Post::STATUS_DRAFT,
        ]);
        $postId = $posts->getInsertID();

        // 본인은 미리보기로 볼 수 있으므로 댓글도 달 수 있다.
        $result = $this->actingAs($owner)->call('POST', "posts/{$postId}/comments", ['body' => '메모용 댓글']);

        $result->assertRedirect();
        $this->seeInDatabase('comments', ['post_id' => $postId, 'body' => '메모용 댓글']);
    }
}
