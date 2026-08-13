<?php

namespace Tests\Feature;

use App\Entities\Post;
use App\Models\CategoryModel;
use App\Models\PostModel;
use App\Models\TagModel;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * 발행 API(POST /api/posts) Feature 테스트.
 *
 * 이 엔드포인트의 계약은 "원고 파일이 진실이고, 몇 번을 보내도 결과가 같다" 이다.
 * 그래서 여기서 가장 중요한 검증은 응답 코드가 아니라 **멱등성** 과
 * **원고가 정한 값이 살아남는가** 다. 오타를 고쳐 다시 보내는 일이 실제로 잦은데,
 * 그때마다 글이 늘어나거나 slug 가 바뀌면 도구로 쓸 수 없다.
 *
 * WithCsrf 트레이트를 쓰지 않는다. api/* 는 CSRF 전역 필터에서 제외돼 있고,
 * 그 사실 자체가 검증 대상이기도 하다 — 토큰만으로 통과해야 한다.
 */
final class PublishApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        // 앞선 테스트의 로그인 상태가 새 나가면 토큰 인증 판정이 흐려진다.
        $_SESSION = [];
        Services::resetSingle('session');
        Services::resetSingle('auth');
    }

    protected function tearDown(): void
    {
        // 주입한 모델이 다음 테스트로 새 나가면 원인을 찾기 어려운 실패가 된다.
        Factories::reset('models');

        parent::tearDown();
    }

    // ---------------------------------------------------------------- 도우미

    private function makeUser(string $username, string $email): User
    {
        $users = auth()->getProvider();
        $user  = new User(['username' => $username, 'email' => $email, 'password' => 'secret-password-123']);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    private function adminToken(): string
    {
        $admin = $this->makeUser('admin', 'admin@example.com');
        $admin->addGroup('admin');

        return $admin->generateAccessToken('test')->raw_token;
    }

    private function memberToken(): string
    {
        return $this->makeUser('member', 'member@example.com')->generateAccessToken('test')->raw_token;
    }

    /** 기본 페이로드. 개별 테스트는 필요한 키만 덮어쓴다. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => '멀티보드 만들기 #3',
            'slug'  => 'ci4-multiboard-03',
            'body'  => '본문입니다.',
        ], $overrides);
    }

    private function publish(?string $token, array $payload): TestResponse
    {
        $headers = $token === null ? [] : ['Authorization' => 'Bearer ' . $token];

        return $this->withHeaders($headers)
            ->withBodyFormat('json')
            ->call('POST', 'api/posts', $payload);
    }

    private function findBySlug(string $slug): ?Post
    {
        return model(PostModel::class)->where('slug', $slug)->first();
    }

    // ---------------------------------------------------------------- 인증

    public function testTokenIsRequired(): void
    {
        $this->publish(null, $this->payload())->assertStatus(401);

        $this->assertNull($this->findBySlug('ci4-multiboard-03'));
    }

    public function testInvalidTokenIsRejected(): void
    {
        $this->publish('완전히-틀린-토큰', $this->payload())->assertStatus(401);
    }

    /**
     * 일반 회원 토큰은 통과하지 못한다.
     *
     * tokens 필터만 걸었다면 여기서 글이 올라간다. api-admin 필터가 하는 일이
     * 이 한 줄로 드러난다 — 그리고 403 JSON 이어야 한다. Shield 의 group 필터를
     * 그대로 썼다면 로그인 페이지로 302 가 나가서 이 단언이 깨진다.
     */
    public function testMemberTokenCannotPublish(): void
    {
        $this->publish($this->memberToken(), $this->payload())->assertStatus(403);

        $this->assertNull($this->findBySlug('ci4-multiboard-03'));
    }

    // ---------------------------------------------------------------- 생성

    public function testAdminTokenCreatesPost(): void
    {
        $this->publish($this->adminToken(), $this->payload())->assertStatus(201);

        $this->seeInDatabase('posts', [
            'slug'  => 'ci4-multiboard-03',
            'title' => '멀티보드 만들기 #3',
        ]);
    }

    /**
     * 상태를 명시하지 않으면 임시저장이다.
     *
     * 실수로 보낸 요청이 곧바로 독자에게 보이지 않게 하는 안전장치라,
     * 기본값이 바뀌면 조용히 사고가 난다.
     */
    public function testDefaultStatusIsDraft(): void
    {
        $this->publish($this->adminToken(), $this->payload())->assertStatus(201);

        $this->assertSame(Post::STATUS_DRAFT, $this->findBySlug('ci4-multiboard-03')->status);
    }

    public function testStatusCanBePublished(): void
    {
        $this->publish($this->adminToken(), $this->payload(['status' => 'published']))->assertStatus(201);

        $this->assertSame(Post::STATUS_PUBLISHED, $this->findBySlug('ci4-multiboard-03')->status);
    }

    public function testUnknownStatusIsRejected(): void
    {
        $this->publish($this->adminToken(), $this->payload(['status' => 'archived']))->assertStatus(400);

        $this->assertNull($this->findBySlug('ci4-multiboard-03'));
    }

    public function testSlugIsRequired(): void
    {
        $this->publish($this->adminToken(), $this->payload(['slug' => '']))->assertStatus(400);
    }

    // ---------------------------------------------------------------- 멱등성

    /**
     * 같은 slug 로 두 번 보내면 글은 하나다.
     *
     * 이 API 의 핵심 계약이다. 원고 오타를 고쳐 다시 보내는 일이 잦은데
     * 그때마다 글이 하나씩 늘어나면 도구로 쓸 수 없다.
     */
    public function testSameSlugUpdatesInsteadOfDuplicating(): void
    {
        $token = $this->adminToken();

        $this->publish($token, $this->payload())->assertStatus(201);
        $this->publish($token, $this->payload(['body' => '고친 본문']))->assertStatus(200);

        $this->assertSame(
            1,
            model(PostModel::class)->where('slug', 'ci4-multiboard-03')->countAllResults(),
            '같은 slug 로 두 번 보냈는데 글이 둘이 됐다.'
        );
        $this->assertSame('고친 본문', $this->findBySlug('ci4-multiboard-03')->body);
    }

    /**
     * 제목을 고쳐도 slug 는 원고가 정한 값 그대로다.
     *
     * PostModel 은 저장 직전 제목으로 slug 를 다시 만든다(generateSlug 콜백).
     * 컨트롤러가 allowCallbacks(false) 로 그 콜백을 끄지 않으면, 제목을 한 글자만
     * 고쳐도 slug 가 바뀌어 다음 발행에서 새 글이 하나 더 생긴다. 이미 발행된 URL 도
     * 함께 깨진다 — 이 테스트가 지키는 것은 사실 독자의 북마크다.
     */
    public function testSlugSurvivesTitleChange(): void
    {
        $token = $this->adminToken();

        $this->publish($token, $this->payload())->assertStatus(201);
        $this->publish($token, $this->payload(['title' => '제목을 완전히 바꿨습니다']))->assertStatus(200);

        $post = $this->findBySlug('ci4-multiboard-03');

        $this->assertNotNull($post, 'slug 가 제목을 따라 바뀌었다.');
        $this->assertSame('제목을 완전히 바꿨습니다', $post->title);
        $this->assertSame(1, model(PostModel::class)->countAllResults());
    }

    // ---------------------------------------------------------------- 발행일

    /**
     * published_at 은 줬을 때만 반영한다.
     *
     * 안 줬는데도 매번 '지금' 으로 밀면, 오타 하나 고칠 때마다 글이 목록 맨 위로
     * 튀어 오른다. 재발행이 목록 순서를 흔들지 않아야 한다.
     */
    public function testPublishedAtIsAppliedOnlyWhenGiven(): void
    {
        $token = $this->adminToken();

        $this->publish($token, $this->payload(['published_at' => '2020-01-02 03:04:05']))->assertStatus(201);
        $this->assertSame(
            '2020-01-02 03:04:05',
            (string) $this->findBySlug('ci4-multiboard-03')->created_at
        );

        // published_at 없이 재발행 — 발행일이 그대로여야 한다.
        $this->publish($token, $this->payload(['body' => '오타 수정']))->assertStatus(200);
        $this->assertSame(
            '2020-01-02 03:04:05',
            (string) $this->findBySlug('ci4-multiboard-03')->created_at,
            '재발행이 발행일을 지금으로 밀어 버렸다.'
        );
    }

    /**
     * 형식이 어긋난 published_at 은 거부한다. 글도 만들지 않는다.
     *
     * 운영 DB 인 SQLite 는 타입을 강제하지 않아 '어제' 같은 값도 created_at 에
     * 그대로 들어간다. 그러면 목록 정렬·RSS·사이트맵의 날짜가 한꺼번에 깨지는데,
     * 발행 시점에는 아무 신호도 없어서 한참 뒤에 발견하게 된다.
     *
     * '2020-13-45 00:00:00' 을 함께 보는 이유: 형식만 맞춰 보면 통과하는 값이다.
     * createFromFormat() 은 13월 45일을 다음 해로 굴려서 받아 주기 때문에,
     * 되돌린 문자열이 원문과 같은지까지 봐야 걸린다.
     */
    public function testInvalidPublishedAtIsRejected(): void
    {
        $token = $this->adminToken();

        foreach (['어제', '2020-13-45 00:00:00', '2020-01-02'] as $value) {
            $this->publish($token, $this->payload(['published_at' => $value]))
                ->assertStatus(400);

            $this->assertNull($this->findBySlug('ci4-multiboard-03'), "'{$value}' 를 받아 글이 만들어졌다.");
        }
    }

    /**
     * 이미 있는 글에 잘못된 published_at 이 오면 그 글은 손대지 않는다.
     *
     * 거부하면서 본문만 갱신해 버리면 "실패했는데 절반은 반영된" 상태가 된다.
     */
    public function testInvalidPublishedAtLeavesExistingPostUntouched(): void
    {
        $token = $this->adminToken();

        $this->publish($token, $this->payload())->assertStatus(201);

        $this->publish($token, $this->payload(['body' => '고친 본문', 'published_at' => '어제']))
            ->assertStatus(400);

        $this->assertSame('본문입니다.', $this->findBySlug('ci4-multiboard-03')->body);
    }

    // ---------------------------------------------------------------- 카테고리

    public function testCategoryResolvesByName(): void
    {
        $categories = model(CategoryModel::class);
        $categories->insert(['name' => 'CodeIgniter4']);
        $id = $categories->getInsertID();

        $this->publish($this->adminToken(), $this->payload(['category' => 'CodeIgniter4']))->assertStatus(201);

        $this->assertSame($id, (int) $this->findBySlug('ci4-multiboard-03')->category_id);
    }

    public function testCategoryResolvesBySlug(): void
    {
        $categories = model(CategoryModel::class);
        $categories->insert(['name' => 'CodeIgniter4', 'slug' => 'ci4']);
        $id = $categories->getInsertID();

        $this->publish($this->adminToken(), $this->payload(['category' => 'ci4']))->assertStatus(201);

        $this->assertSame($id, (int) $this->findBySlug('ci4-multiboard-03')->category_id);
    }

    /**
     * 없는 카테고리는 거부한다. 자동으로 만들지 않는다.
     *
     * 오타 한 번에 'CodeIgniter4' 와 'Codeigniter4' 가 나란히 생기면 목록이 갈라지고,
     * 되돌리려면 글을 옮겨야 한다. 글도 만들어지면 안 된다.
     */
    public function testUnknownCategoryIsRejectedAndNothingIsWritten(): void
    {
        $this->publish($this->adminToken(), $this->payload(['category' => '없는분류']))->assertStatus(400);

        $this->assertNull($this->findBySlug('ci4-multiboard-03'));
        $this->assertSame(0, model(CategoryModel::class)->countAllResults());
    }

    // ---------------------------------------------------------------- 태그

    public function testTagsAreSynced(): void
    {
        $this->publish($this->adminToken(), $this->payload(['tags' => ['CodeIgniter4', '게시판']]))
            ->assertStatus(201);

        $names = array_map(
            static fn ($tag) => $tag->name,
            model(TagModel::class)->forPost((int) $this->findBySlug('ci4-multiboard-03')->id)
        );

        sort($names);
        $this->assertSame(['CodeIgniter4', '게시판'], $names);
    }

    public function testTagsAcceptCommaSeparatedString(): void
    {
        $this->publish($this->adminToken(), $this->payload(['tags' => 'CodeIgniter4, 게시판']))
            ->assertStatus(201);

        $this->assertCount(
            2,
            model(TagModel::class)->forPost((int) $this->findBySlug('ci4-multiboard-03')->id)
        );
    }

    /**
     * tags 키를 아예 안 보내면 기존 태그를 손대지 않는다.
     *
     * "언급하지 않음" 과 "비우고 싶음" 은 다른 요청이다. 이 구분이 없으면
     * 본문만 고쳐 다시 보낼 때마다 태그가 조용히 날아간다.
     */
    public function testOmittingTagsLeavesThemAlone(): void
    {
        $token = $this->adminToken();

        $this->publish($token, $this->payload(['tags' => ['CodeIgniter4']]))->assertStatus(201);
        $this->publish($token, $this->payload(['body' => '본문만 고침']))->assertStatus(200);

        $this->assertCount(
            1,
            model(TagModel::class)->forPost((int) $this->findBySlug('ci4-multiboard-03')->id),
            'tags 를 안 보냈는데 태그가 사라졌다.'
        );
    }

    public function testEmptyTagArrayClearsThem(): void
    {
        $token = $this->adminToken();

        $this->publish($token, $this->payload(['tags' => ['CodeIgniter4']]))->assertStatus(201);
        $this->publish($token, $this->payload(['tags' => []]))->assertStatus(200);

        $this->assertCount(
            0,
            model(TagModel::class)->forPost((int) $this->findBySlug('ci4-multiboard-03')->id)
        );
    }

    // ---------------------------------------------------------------- 이미지

    /**
     * 업로드되지 않은 파일명은 거부한다.
     *
     * 그대로 저장하면 목록에 깨진 이미지가 남는다. 업로드 단계가 실패했는데
     * 발행만 성공하는 상황을 막는 방어다.
     */
    public function testUnknownImageIsRejected(): void
    {
        $this->publish($this->adminToken(), $this->payload(['image' => 'never-uploaded.png']))
            ->assertStatus(400);

        $this->assertNull($this->findBySlug('ci4-multiboard-03'));
    }

    // ---------------------------------------------------------------- 트랜잭션

    /**
     * 글이 저장된 뒤 실패하면 그 글도 남지 않는다.
     *
     * 실제로 겪은 사고를 재현한다. 태그 테이블이 없던 환경에서 글은 만들어지고
     * 요청은 500 으로 끝나, 스크립트는 실패로 보고했는데 DB 에는 초안이 떠 있었다.
     * 발행은 사람이 화면을 보지 않는 자리에서 도는 일이라 이 반쪽 상태가 특히 나쁘다.
     *
     * 고장은 TagModel 을 던지는 것으로 바꿔서 만든다. 처음에는 post_tags 테이블을
     * 잠깐 지웠다가 되돌리는 방식으로 짰는데, 테스트 DB 가 인메모리 SQLite 라
     * 그 수술이 뒤따르는 테스트들까지 통째로 무너뜨렸다. 스키마는 공유 자원이고,
     * 여기서 확인하고 싶은 것은 스키마가 아니라 컨트롤러의 롤백 동작이다.
     */
    public function testFailureAfterInsertRollsBackThePost(): void
    {
        Factories::injectMock('models', TagModel::class, new class () extends TagModel {
            public function syncForPost(int $postId, array $names): void
            {
                throw new DatabaseException("Table 'ci4.post_tags' doesn't exist");
            }
        });

        $this->publish($this->adminToken(), $this->payload(['tags' => ['태그']]))
            ->assertStatus(500);

        $this->assertNull(
            $this->findBySlug('ci4-multiboard-03'),
            '태그 단계에서 실패했는데 글이 남았다 — 트랜잭션이 풀렸다.'
        );
    }

    // ---------------------------------------------------------------- 조회

    public function testShowReturnsStatusAndTags(): void
    {
        $token = $this->adminToken();
        $this->publish($token, $this->payload(['tags' => ['CodeIgniter4']]))->assertStatus(201);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->call('GET', 'api/posts/ci4-multiboard-03');

        $result->assertStatus(200);

        // response()->getBody() 여야 한다. TestResponse::getBody() 는 DOMParser 를
        // 거치면서 본문을 HTML 로 감싸고 한글을 숫자 엔티티로 바꾼다.
        $body = json_decode($result->response()->getBody(), true);

        $this->assertSame('ci4-multiboard-03', $body['slug']);
        $this->assertSame(Post::STATUS_DRAFT, $body['status']);
        $this->assertSame(['CodeIgniter4'], $body['tags']);
    }

    public function testShowReturnsNotFoundForUnknownSlug(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken()])
            ->call('GET', 'api/posts/없는글')
            ->assertStatus(404);
    }
}
