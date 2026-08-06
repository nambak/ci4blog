<?php

namespace Tests\Feature;

use App\Entities\Post;
use App\Models\PostModel;
use App\Models\TagModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\WithCsrf;

/**
 * 글 태그. (#114)
 *
 * 입력은 JS 없는 쉼표 구분 텍스트이고 서버가 다듬는다.
 * 중복 방지의 중심은 (post_id, tag_id) 유니크 키다 — 검사-후-삽입은
 * 동시 요청 둘이 모두 통과하는 레이스가 있어 DB 가 막아야 한다(#88).
 */
final class PostTagsTest extends CIUnitTestCase
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
        \Config\Services::resetSingle('session');
        \Config\Services::resetSingle('auth');
    }

    private function makeUser(string $username = 'writer', string $email = 'writer@example.com'): User
    {
        $users = auth()->getProvider();
        $user  = new User(['username' => $username, 'email' => $email, 'password' => 'secret-password-123']);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    private function makePost(int $userId, array $overrides = []): Post
    {
        $posts = model(PostModel::class);
        $posts->insert(array_merge([
            'user_id' => $userId,
            'title'   => 'Tag Test Post',
            'body'    => '본문',
            'status'  => Post::STATUS_PUBLISHED,
        ], $overrides));

        return $posts->find($posts->getInsertID());
    }

    /** 그 글에 붙은 태그 이름 목록(이름 오름차순). */
    private function tagNamesOf(int $postId): array
    {
        return array_map(
            static fn ($tag): string => $tag->name,
            model(TagModel::class)->forPost($postId)
        );
    }

    public function testParseTrimsAndDropsEmpty(): void
    {
        $names = model(TagModel::class)->parseNames('  CI4 ,, 마이그레이션  ,  ');

        $this->assertSame(['CI4', '마이그레이션'], $names);
    }

    /** 🔴 대소문자만 다른 것은 같은 태그다. 먼저 나온 표기를 남긴다. */
    public function testParseDropsCaseInsensitiveDuplicates(): void
    {
        $names = model(TagModel::class)->parseNames('CI4, ci4, Ci4, 마이그레이션');

        $this->assertSame(['CI4', '마이그레이션'], $names);
    }

    /** 🔴 상한이 없으면 폼 하나로 태그를 수천 개 만들 수 있다. */
    public function testParseEnforcesCountAndLengthLimits(): void
    {
        $tagModel = model(TagModel::class);

        // 개수 상한 10
        $many = implode(',', array_map(static fn (int $i): string => "tag{$i}", range(1, 15)));
        $this->assertCount(10, $tagModel->parseNames($many));

        // 길이 상한 50 — 51자는 버린다
        $names = $tagModel->parseNames(str_repeat('가', 51) . ', 짧은태그');
        $this->assertSame(['짧은태그'], $names);
    }

    public function testSyncAttachesTagsToPost(): void
    {
        $post = $this->makePost((int) $this->makeUser()->id);

        model(TagModel::class)->syncForPost((int) $post->id, ['CI4', '마이그레이션']);

        $this->assertSame(['CI4', '마이그레이션'], $this->tagNamesOf((int) $post->id));
    }

    /** 🔴 같은 이름의 태그 행이 새로 생기지 않는다 — 기존 것을 재사용한다. */
    public function testSyncReusesExistingTags(): void
    {
        $user   = $this->makeUser();
        $first  = $this->makePost((int) $user->id, ['title' => 'First']);
        $second = $this->makePost((int) $user->id, ['title' => 'Second']);

        $tagModel = model(TagModel::class);
        $tagModel->syncForPost((int) $first->id, ['CI4']);
        // 대소문자가 달라도 같은 태그로 본다.
        $tagModel->syncForPost((int) $second->id, ['ci4']);

        $rows = db_connect()->table('tags')->get()->getResultArray();

        $this->assertCount(1, $rows, '태그 행이 하나여야 한다');
        $this->assertSame('CI4', $rows[0]['name'], '먼저 만들어진 표기를 유지한다');
        $this->assertSame(['CI4'], $this->tagNamesOf((int) $second->id));
    }

    /** 수정은 전체 교체다 — 빠진 태그는 연결이 끊긴다. */
    public function testSyncReplacesInsteadOfAppending(): void
    {
        $post     = $this->makePost((int) $this->makeUser()->id);
        $tagModel = model(TagModel::class);

        $tagModel->syncForPost((int) $post->id, ['CI4', '마이그레이션']);
        $tagModel->syncForPost((int) $post->id, ['CI4', '테스트']);

        $this->assertSame(['CI4', '테스트'], $this->tagNamesOf((int) $post->id));
    }
}
