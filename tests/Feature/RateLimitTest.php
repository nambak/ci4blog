<?php

namespace Tests\Feature;

use App\Entities\Post;
use App\Models\CommentModel;
use App\Models\PostModel;
use CodeIgniter\Config\Factories;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\RateLimit;
use Config\Services;
use Tests\Support\Traits\WithCsrf;

/**
 * 요청 속도 제한(#111)이 실제 라우트에 걸려 있는지 본다.
 *
 * POST 를 호출하므로 FeatureTestTrait 대신 WithCsrf 를 쓴다(#73).
 *
 * @internal
 */
final class RateLimitTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use WithCsrf;
    use AuthenticationTesting;

    protected $namespace = null;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        Services::resetSingle('session');
        Services::resetSingle('auth');
        Services::resetSingle('throttler');
        // 파일 캐시라 토큰이 테스트 사이로 샌다. 앞 테스트가 남긴 토큰이
        // 뒤 테스트를 엉뚱한 이유로 차단하지 않도록 비운다.
        cache()->clean();
    }

    protected function tearDown(): void
    {
        cache()->clean();
        Factories::reset('config');
        parent::tearDown();
    }

    /**
     * 임계값을 낮춰 주입한다. 30회를 실제로 호출하지 않고 경계를 본다.
     *
     * enabled 를 명시적으로 켜는 것이 중요하다 — phpunit.dist.xml 이
     * 테스트 환경에서 속도 제한을 기본 off 로 두기 때문에, new RateLimit()
     * 은 enabled=false 인 상태로 만들어진다.
     */
    private function limitTo(int $capacity): void
    {
        $config          = new RateLimit();
        $config->enabled = true;

        foreach (array_keys($config->limits) as $action) {
            $config->limits[$action]['capacity'] = $capacity;
        }

        Factories::injectMock('config', 'RateLimit', $config);
    }

    private function makeUser(string $username, string $email): User
    {
        $users = auth()->getProvider();
        $user  = new User(['username' => $username, 'email' => $email, 'password' => 'secret-password-123']);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    private function makePost(int $userId): Post
    {
        $posts = model(PostModel::class);
        $posts->insert([
            'user_id' => $userId,
            'title'   => '속도 제한 대상 글',
            'body'    => '본문',
            'status'  => Post::STATUS_PUBLISHED,
        ]);

        return $posts->find($posts->getInsertID());
    }

    private function insertComment(int $postId, int $userId, string $body): int
    {
        $model = model(CommentModel::class);
        $model->insert(['post_id' => $postId, 'user_id' => $userId, 'body' => $body]);

        return $model->getInsertID();
    }

    public function testLikeIsBlockedAfterCapacity(): void
    {
        $this->limitTo(2);
        $author = $this->makeUser('author', 'author@example.com');
        $liker  = $this->makeUser('liker', 'liker@example.com');
        $post   = $this->makePost((int) $author->id);

        $this->actingAs($liker)->call('POST', "posts/{$post->id}/like");
        $this->actingAs($liker)->call('POST', "posts/{$post->id}/like");
        $result = $this->actingAs($liker)->call('POST', "posts/{$post->id}/like");

        $result->assertRedirect();
        $this->assertStringContainsString('요청이 너무 잦습니다', (string) session('message'));
    }

    public function testWithinCapacityHasNoThrottleMessage(): void
    {
        $this->limitTo(2);
        $author = $this->makeUser('author', 'author@example.com');
        $liker  = $this->makeUser('liker', 'liker@example.com');
        $post   = $this->makePost((int) $author->id);

        $this->actingAs($liker)->call('POST', "posts/{$post->id}/like");

        // 음성 단언: 한도 안에서는 제한 메시지가 나오면 안 된다.
        $this->assertStringNotContainsString('요청이 너무 잦습니다', (string) session('message'));
    }

    public function testOtherUserIsNotBlocked(): void
    {
        $this->limitTo(2);
        $author = $this->makeUser('author', 'author@example.com');
        $heavy  = $this->makeUser('heavy', 'heavy@example.com');
        $other  = $this->makeUser('other', 'other@example.com');
        $post   = $this->makePost((int) $author->id);

        // heavy 가 한도를 소진한다.
        $this->actingAs($heavy)->call('POST', "posts/{$post->id}/like");
        $this->actingAs($heavy)->call('POST', "posts/{$post->id}/like");
        $this->actingAs($heavy)->call('POST', "posts/{$post->id}/like");

        // 키에 사용자 ID 가 들어가야 other 가 영향을 받지 않는다.
        $result = $this->actingAs($other)->call('POST', "posts/{$post->id}/like");

        $result->assertRedirect();
        $this->assertStringNotContainsString('요청이 너무 잦습니다', (string) session('message'));
    }

    public function testCommentStoreIsBlockedAfterCapacity(): void
    {
        $this->limitTo(2);
        $author    = $this->makeUser('author', 'author@example.com');
        $commenter = $this->makeUser('commenter', 'commenter@example.com');
        $post      = $this->makePost((int) $author->id);

        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($commenter)->call('POST', "posts/{$post->id}/comments", ['body' => "댓글 {$i}"]);
        }

        $result = $this->actingAs($commenter)->call('POST', "posts/{$post->id}/comments", ['body' => '초과 댓글']);

        $result->assertRedirect();
        $this->assertStringContainsString('요청이 너무 잦습니다', (string) session('message'));
    }

    public function testReportIsBlockedAfterCapacity(): void
    {
        $this->limitTo(2);
        $author   = $this->makeUser('author', 'author@example.com');
        $reporter = $this->makeUser('reporter', 'reporter@example.com');
        $post     = $this->makePost((int) $author->id);

        // 서로 다른 댓글을 신고한다 — 같은 댓글을 두 번 신고하면 유니크 제약에
        // 걸려 "이미 신고한 댓글입니다" 가 나오므로 제한 여부를 볼 수 없다.
        $first  = $this->insertComment((int) $post->id, (int) $author->id, '첫 댓글');
        $second = $this->insertComment((int) $post->id, (int) $author->id, '둘째 댓글');
        $third  = $this->insertComment((int) $post->id, (int) $author->id, '셋째 댓글');

        $this->actingAs($reporter)->call('POST', "comments/{$first}/report", ['reason' => 'spam']);
        $this->actingAs($reporter)->call('POST', "comments/{$second}/report", ['reason' => 'spam']);
        $result = $this->actingAs($reporter)->call('POST', "comments/{$third}/report", ['reason' => 'spam']);

        $result->assertRedirect();
        $this->assertStringContainsString('요청이 너무 잦습니다', (string) session('message'));
    }

    public function testLoginIsBlockedAfterCapacity(): void
    {
        $this->limitTo(2);
        $this->makeUser('victim', 'victim@example.com');

        $attempt = fn () => $this->call('POST', 'login', [
            'email'    => 'victim@example.com',
            'password' => 'wrong-password',
        ]);

        $attempt();
        $attempt();
        $result = $attempt();

        $result->assertRedirect();
        // 로그인 화면은 Shield 뷰가 session('error') 를 표시한다.
        $this->assertStringContainsString('요청이 너무 잦습니다', (string) session('error'));
    }

    public function testLoginFormPageIsNotCounted(): void
    {
        $this->limitTo(2);
        $this->makeUser('victim', 'victim@example.com');

        // GET 으로 로그인 폼을 여러 번 열어도 토큰이 줄지 않아야 한다.
        $this->call('GET', 'login');
        $this->call('GET', 'login');
        $this->call('GET', 'login');

        $result = $this->call('POST', 'login', [
            'email'    => 'victim@example.com',
            'password' => 'wrong-password',
        ]);

        $result->assertRedirect();
        $this->assertStringNotContainsString('요청이 너무 잦습니다', (string) session('error'));
    }
}
