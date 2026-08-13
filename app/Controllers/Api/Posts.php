<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Entities\Post;
use App\Models\CategoryModel;
use App\Models\PostModel;
use App\Models\TagModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use Throwable;

/**
 * 원고 발행 API.
 *
 * 강의 원고를 손으로 관리자 화면에 옮겨 적지 않기 위한 창구다. 원고 파일이
 * 진실이고, 이 엔드포인트는 그 파일을 DB 에 반영하는 통로일 뿐이다.
 *
 * slug 가 upsert 기준 키다 — 같은 원고를 몇 번 보내도 결과가 같아야 한다.
 * 오타를 고쳐 다시 보내는 일이 실제로 잦고, 그때마다 글이 하나씩 늘어나면
 * 쓸 수 없는 도구가 된다(posts:import 가 같은 이유로 slug 를 키로 삼는다).
 *
 * 인증은 Shield 액세스 토큰(tokens 필터) + 관리자 그룹이다. 라우트에서 건다.
 */
class Posts extends BaseController
{
    use ResponseTrait;

    /**
     * 발행/갱신. POST /api/posts
     *
     * 본문(JSON):
     *   title        필수  글 제목
     *   slug         필수  URL 식별자이자 upsert 기준 키
     *   body         필수  마크다운 원문 (저장은 원문, 표시만 변환 — ep26)
     *   category     선택  카테고리 슬러그 또는 이름
     *   tags         선택  문자열 배열 또는 쉼표 구분 문자열
     *   status       선택  draft | published | private (기본 draft)
     *   image        선택  POST /api/uploads 가 돌려준 파일명
     *   published_at 선택  'Y-m-d H:i:s'. 주면 created_at 을 이 값으로 맞춘다
     */
    public function upsert(): ResponseInterface
    {
        $in = $this->request->getJSON(true);

        if (! is_array($in)) {
            return $this->failValidationErrors(['body' => 'JSON 본문을 보내 주세요.']);
        }

        $title = trim((string) ($in['title'] ?? ''));
        $slug  = trim((string) ($in['slug'] ?? ''));
        $body  = (string) ($in['body'] ?? '');

        if ($slug === '') {
            // 제목에서 만들어 주지 않는다. slug 는 발행 후 바뀌면 안 되는 값이고,
            // 그 결정을 원고 파일이 갖고 있어야 재발행이 멱등해진다.
            return $this->failValidationErrors(['slug' => 'slug 는 필수입니다. 원고 front matter 에 적어 주세요.']);
        }

        // 상태는 기본을 draft 로 둔다. 실수로 보낸 요청이 곧바로 공개되지 않게 하는
        // 안전장치다 — 공개는 명시적으로 요청해야 한다.
        $status = (string) ($in['status'] ?? Post::STATUS_DRAFT);

        if (! in_array($status, Post::STATUSES, true)) {
            return $this->failValidationErrors([
                'status' => 'status 는 ' . implode(' / ', Post::STATUSES) . ' 중 하나여야 합니다.',
            ]);
        }

        $categoryId = null;

        if (($rawCategory = trim((string) ($in['category'] ?? ''))) !== '') {
            $categoryId = $this->resolveCategoryId($rawCategory);

            if ($categoryId === null) {
                return $this->failValidationErrors([
                    'category' => "'{$rawCategory}' 카테고리를 찾을 수 없습니다. 관리자 화면에서 먼저 만들어 주세요.",
                ]);
            }
        }

        $image = trim((string) ($in['image'] ?? ''));

        if ($image !== '' && ! is_file(WRITEPATH . 'uploads/' . basename($image))) {
            // 없는 파일명을 그대로 저장하면 목록에 깨진 이미지가 남는다.
            // 업로드 단계에서 실패했는데 발행이 성공하는 상황을 막는다.
            return $this->failValidationErrors([
                'image' => "업로드된 이미지가 없습니다: {$image}. POST /api/uploads 를 먼저 호출하세요.",
            ]);
        }

        // 형식 검증은 트랜잭션 밖에서 끝낸다. 여기서 걸러 두면 글을 만들었다가
        // 되돌릴 일이 없다.
        $publishedAt = trim((string) ($in['published_at'] ?? ''));

        if ($publishedAt !== '' && ! $this->isValidTimestamp($publishedAt)) {
            return $this->failValidationErrors([
                'published_at' => "published_at 은 'Y-m-d H:i:s' 형식이어야 합니다: {$publishedAt}",
            ]);
        }

        $posts = model(PostModel::class);
        $db    = db_connect();

        $existing = $posts->where('slug', $slug)->first();

        $data = [
            'title'       => $title,
            'slug'        => $slug,
            'body'        => $body,
            'category_id' => $categoryId,
            'status'      => $status,
            // 인증자를 명시한다. auth() 는 기본 인증자(session)를 돌려주는데
            // 토큰 요청에는 세션이 없어 null 이 들어간다.
            'user_id'     => auth('tokens')->id(),
        ];

        if ($image !== '') {
            $data['image'] = basename($image);
        }

        // 글 저장·발행일·태그를 한 트랜잭션으로 묶는다.
        //
        // 나누면 "글은 만들어졌는데 태그에서 실패" 같은 반쪽 상태가 남는다. 발행은
        // 스크립트가 돌리는 일이라 사람이 화면을 보고 있지 않고, 응답만 보고 실패로
        // 판단해 다시 보내면 그 사이에 초안이 하나 떠 있는 셈이 된다.
        //
        // transStart() 가 아니라 transBegin() + try 를 쓰는 이유: 예외가 나면
        // transComplete() 까지 못 가서 롤백 시점이 연결 종료에 맡겨진다. 여기서
        // 직접 잡아야 롤백도 확실하고, 500 덤프 대신 읽을 수 있는 JSON 을 돌려줄 수 있다.
        $db->transBegin();

        try {
            $model = $posts->allowCallbacks(false);

            if ($existing !== null) {
                if (! $model->update($existing->id, $data)) {
                    $db->transRollback();

                    return $this->failValidationErrors($posts->errors());
                }

                $postId  = (int) $existing->id;
                $outcome = 'updated';
            } else {
                $id = $model->insert($data);

                if ($id === false) {
                    $db->transRollback();

                    return $this->failValidationErrors($posts->errors());
                }

                $postId  = (int) $id;
                $outcome = 'created';
            }

            // 발행 시각은 명시했을 때만 되맞춘다. 안 주면 created_at 을 건드리지 않아
            // 재발행해도 원래 발행일이 유지된다 — 이걸 매번 '지금' 으로 밀면 오타 하나
            // 고칠 때마다 글이 목록 맨 위로 튀어 오른다.
            if ($publishedAt !== '') {
                $db->table('posts')->where('id', $postId)->update(['created_at' => $publishedAt]);
            }

            $this->syncTags($postId, $in['tags'] ?? null);

            $db->transCommit();
        } catch (Throwable $e) {
            $db->transRollback();

            log_message('error', '발행 API 실패: {message}', ['message' => $e->getMessage()]);

            return $this->failServerError($this->explain($e));
        }

        $post = $posts->find($postId);

        return $this->respond([
            'outcome' => $outcome,
            'id'      => $postId,
            'slug'    => $post->slug,
            'status'  => $post->status,
            'url'     => $post->url,
        ], $outcome === 'created' ? 201 : 200);
    }

    /**
     * 발행 상태 확인. GET /api/posts/{slug}
     *
     * 스크립트가 "이미 올라가 있는가" 를 확인할 때 쓴다.
     */
    public function show(string $slug): ResponseInterface
    {
        $post = model(PostModel::class)->where('slug', $slug)->first();

        if ($post === null) {
            return $this->failNotFound("글을 찾을 수 없습니다: {$slug}");
        }

        return $this->respond([
            'id'         => (int) $post->id,
            'title'      => $post->title,
            'slug'       => $post->slug,
            'status'     => $post->status,
            'image'      => $post->image,
            'url'        => $post->url,
            'created_at' => (string) $post->created_at,
            'updated_at' => (string) $post->updated_at,
            'tags'       => array_map(
                static fn ($tag) => $tag->name,
                model(TagModel::class)->forPost((int) $post->id)
            ),
        ]);
    }

    /**
     * 예외를 스크립트가 읽고 조치할 수 있는 한 줄로 바꾼다.
     *
     * 발행은 터미널에서 돌아가므로, 프레임워크 덤프를 그대로 던지면 스크롤만
     * 길어지고 무엇을 해야 하는지는 안 보인다. 자주 나오는 원인 한 가지는
     * 이름을 붙여 준다 — 마이그레이션이 밀린 상태다.
     */
    private function explain(Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, "doesn't exist") || str_contains($message, 'no such table')) {
            return $message . ' — 블로그에서 php spark migrate 를 먼저 실행하세요.';
        }

        return $message;
    }

    /**
     * 'Y-m-d H:i:s' 형식의 실제 존재하는 시각인지 본다.
     *
     * createFromFormat() 만으로는 부족하다. 13월 45일 같은 값을 다음 해로 굴려서
     * 받아 주기 때문에, 되돌린 문자열이 원문과 같은지까지 봐야 진짜 그 날짜다.
     */
    private function isValidTimestamp(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

        return $parsed !== false && $parsed->format('Y-m-d H:i:s') === $value;
    }

    /**
     * 카테고리 슬러그 또는 이름으로 id 를 찾는다. 없으면 null.
     *
     * 없는 카테고리를 자동으로 만들지 않는다 — 오타 한 번에 'CodeIgniter4' 와
     * 'Codeigniter4' 가 나란히 생기면 목록이 갈라지고, 되돌리려면 글을 옮겨야 한다.
     */
    private function resolveCategoryId(string $needle): ?int
    {
        $categories = model(CategoryModel::class);

        $found = $categories->where('slug', $needle)->first()
            ?? $categories->where('LOWER(name)', mb_strtolower($needle))->first();

        return $found === null ? null : (int) $found->id;
    }

    /**
     * 태그를 동기화한다. 배열과 쉼표 문자열을 모두 받는다.
     *
     * 개수·길이 제한은 TagModel::parseNames() 에 맡긴다. 관리자 화면과 API 가
     * 같은 규칙을 쓰게 하려는 것이다 — 여기서 따로 세면 두 경로가 갈라진다.
     *
     * @param mixed $tags
     */
    private function syncTags(int $postId, $tags): void
    {
        if ($tags === null) {
            // 키 자체를 안 보냈으면 손대지 않는다. 빈 배열([])을 보내면 전부 지운다 —
            // "언급하지 않음" 과 "비우고 싶음" 은 다른 요청이다.
            return;
        }

        $input = is_array($tags) ? implode(',', array_map('strval', $tags)) : (string) $tags;

        model(TagModel::class)->syncForPost($postId, model(TagModel::class)->parseNames($input));
    }
}
