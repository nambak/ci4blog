<?php

namespace App\Controllers;

use App\Entities\Post;
use App\Models\CategoryModel;
use App\Models\CommentModel;
use App\Models\PostLikeModel;
use App\Models\PostModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

class Posts extends BaseController
{
    // 한 페이지에 보여 줄 글 수
    private const PER_PAGE = 10;

    /**
     * 글 목록. 카테고리 슬러그가 주어지면 그 카테고리 글만 거르고,
     * 검색어(q)가 주어지면 제목·본문을 like 로 찾는다.
     *
     * `posts` → 전체, `categories/{slug}` → 해당 카테고리, `?q=...` → 검색.
     */
    public function index(?string $categorySlug = null): string
    {
        // 공개 목록은 발행된 글만 보여 준다. 카테고리·검색 조건과 AND 로 묶인다.
        $model = model(PostModel::class)->published();

        // 없는 카테고리는 404. (필터가 빈 목록으로 조용히 떨어지지 않게)
        // 숨김 카테고리(is_visible = 0)도 공개 화면에서는 없는 것과 같게 다룬다(#67) —
        // 403 이 아니라 404 인 이유는 아래 show() 의 주석과 같다.
        $activeCategory = null;
        if ($categorySlug !== null) {
            $activeCategory = model(CategoryModel::class)
                ->where('slug', $categorySlug)
                ->where('is_visible', 1)
                ->first();
            if ($activeCategory === null) {
                throw PageNotFoundException::forPageNotFound();
            }
            $model->where('category_id', $activeCategory->id);
        }

        // 검색어가 있으면 제목 OR 본문에서 찾는다. 다른 조건(카테고리)과 AND 로 묶이도록
        // like 묶음을 groupStart/End 로 감싼다.
        $search = trim((string) $this->request->getGet('q'));
        if ($search !== '') {
            $model->groupStart()
                ->like('title', $search)
                ->orLike('body', $search)
                ->groupEnd();
        }

        $posts = $model
            ->orderBy('created_at', 'DESC')
            ->paginate(self::PER_PAGE);

        return view('posts/index', [
            'posts'          => $posts,
            'pager'          => $model->pager,
            'categories'     => model(CategoryModel::class)->menu(),
            'activeCategory' => $activeCategory,
            'search'         => $search,
        ]);
    }

    public function show(string $slug): string
    {
        $post = model(PostModel::class)
            ->where('slug', $slug)
            ->first();

        // 없는 글은 404 로 응답한다.
        if ($post === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $this->assertViewable($post);

        // 이 글의 댓글을 작성자명과 함께 한 번에 로드한다(N+1 회피).
        $comments     = model(CommentModel::class)->forPost((int) $post->id);
        $commentCount = model(CommentModel::class)->countForPost((int) $post->id);

        // 바이라인(작성자 아바타 행)용 작성자명. 홈 히어로와 같은 방식으로
        // users 테이블에서 username 만 직접 읽는다(엔티티 의존 없이).
        $authorName   = null;
        $authorAvatar = null;
        if ($post->user_id !== null) {
            $row          = db_connect()->table('users')->select('username, avatar')->where('id', $post->user_id)->get()->getRow();
            $authorName   = $row->username ?? null;
            $authorAvatar = $row->avatar ?? null;
        }

        // 제목 위 카테고리 칩. 없는 글(미분류)은 null.
        $category = $post->category_id !== null
            ? model(CategoryModel::class)->find($post->category_id)
            : null;

        // 좋아요(#64): 카운트는 상세에만 둔다. 목록까지 세면 글마다 쿼리가 돌아 N+1 이 된다.
        $likes     = model(PostLikeModel::class);
        $likeCount = $likes->countForPost((int) $post->id);
        $liked     = auth()->loggedIn() && $likes->hasLiked((int) $post->id, (int) auth()->id());

        return view('posts/show', [
            'post'         => $post,
            'likeCount'    => $likeCount,
            'liked'        => $liked,
            'comments'     => $comments,
            'commentCount' => $commentCount,
            'authorName'   => $authorName,
            'authorAvatar' => $authorAvatar,
            'category'     => $category,
        ]);
    }

    /**
     * 글 작성 폼을 보여 준다. (세션 필터로 로그인 사용자만 접근)
     *
     * 폼 뷰(posts/create)는 ep13 에서 추가한다.
     */
    public function new(): string
    {
        return view('posts/create', [
            // 폼은 숨김 카테고리도 고를 수 있어야 한다 — forForm() 주석 참고(#67).
            'categories' => model(CategoryModel::class)->forForm(),
        ]);
    }

    /**
     * 폼에서 넘어온 글을 검증하고 저장한다.
     */
    public function create(): RedirectResponse
    {
        $model = model(PostModel::class);

        // allowedFields 에 든 값만 추려서 받는다.
        $data = $this->request->getPost(['title', 'body', 'category_id', 'status']);

        // 카테고리는 선택 사항. 안 고르면 빈 문자열로 오므로 null 로 정규화한다.
        $data['category_id'] = $this->normalizeCategoryId($data['category_id'] ?? null);

        // 상태는 폼 셀렉트에서 온다. 없거나 이상한 값이면 발행으로 본다(기존 동작 유지).
        $data['status'] = $this->normalizeStatus($data['status'] ?? null);

        // 현재 로그인한 사용자를 작성자로 묶는다.
        $data['user_id'] = auth()->id();

        // 대표 이미지(선택). 검증 실패면 false, 미업로드면 null, 성공이면 파일명.
        $image = $this->saveUploadedImage();
        if ($image === false) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }
        if ($image !== null) {
            $data['image'] = $image;
        }

        // slug 는 PostModel 의 beforeInsert 콜백이 제목으로 자동 생성한다.

        // 검증 실패 시: 방금 옮긴 이미지 파일을 되돌리고(고아 방지) 폼으로 돌아간다.
        if (! $model->insert($data)) {
            if ($image !== null) {
                $this->deleteImageFiles($image);
            }

            return redirect()->back()
                ->withInput()
                ->with('errors', $model->errors());
        }

        // 저장 성공 시: 목록으로 이동하며 플래시 메시지를 남긴다.
        return redirect()->to('posts')->with('message', '글이 등록되었습니다.');
    }

    /**
     * 글 수정 폼을 보여 준다. 기존 값을 폼에 채운다.
     */
    public function edit(int $id): string|ResponseInterface
    {
        $post = model(PostModel::class)->find($id);

        if ($post === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        // 작성자 본인 또는 관리자만 수정할 수 있다.
        if (! $this->canModify($post)) {
            return $this->response->setStatusCode(403, '수정 권한이 없습니다.');
        }

        return view('posts/edit', [
            'post' => $post,
            // 이 글이 숨김 카테고리에 속해 있어도 목록에 있어야 선택이 유지된다(#67).
            'categories' => model(CategoryModel::class)->forForm(),
        ]);
    }

    /**
     * 수정된 값을 검증하고 저장한다.
     */
    public function update(int $id): RedirectResponse|ResponseInterface
    {
        $model = model(PostModel::class);
        $post  = $model->find($id);

        if ($post === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        // 작성자 본인 또는 관리자만 수정할 수 있다.
        if (! $this->canModify($post)) {
            return $this->response->setStatusCode(403, '수정 권한이 없습니다.');
        }

        $data = $this->request->getPost(['title', 'body', 'category_id', 'status']);

        // 카테고리는 선택 사항. 안 고르면 빈 문자열로 오므로 null 로 정규화한다.
        $data['category_id'] = $this->normalizeCategoryId($data['category_id'] ?? null);

        // 상태는 폼 셀렉트에서 온다. 없거나 이상한 값이면 발행으로 본다(기존 동작 유지).
        $data['status'] = $this->normalizeStatus($data['status'] ?? null);

        // 새 대표 이미지가 올라오면 교체한다. 단 기존 파일은 DB 반영이
        // 성공한 뒤에 지운다(실패 시 기존 이미지 참조가 깨지지 않도록).
        $image    = $this->saveUploadedImage();
        $oldImage = $post->image;
        if ($image === false) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }
        if ($image !== null) {
            $data['image'] = $image;
        }

        // 검증 실패 시: 방금 옮긴 새 파일을 되돌리고 입력값을 들고 폼으로 돌아간다.
        if (! $model->update($id, $data)) {
            if ($image !== null) {
                $this->deleteImageFiles($image);
            }

            return redirect()->back()
                ->withInput()
                ->with('errors', $model->errors());
        }

        // 반영 성공 후에야 기존 이미지를 정리한다.
        if ($image !== null) {
            $this->deleteImageFiles($oldImage);
        }

        // 수정 성공 시: 해당 글 상세로 이동하며 플래시 메시지를 남긴다.
        return redirect()->to('posts/' . $post->slug)->with('message', '글이 수정되었습니다.');
    }

    /**
     * 폼에서 넘어온 category_id 를 저장용 값으로 정규화한다.
     * 미선택(빈 문자열/공백)은 null, 그 외에는 정수로 돌려준다.
     * 실존 여부 검증은 PostModel 의 is_not_unique 규칙이 맡는다.
     */
    private function normalizeCategoryId(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * 폼에서 넘어온 status 를 저장용 값으로 정규화한다.
     * 미지정·허용 값 밖이면 published(기존 동작)로 떨어뜨린다.
     */
    private function normalizeStatus(mixed $value): string
    {
        // status[] 처럼 배열로 조작해 오면 (string) 캐스팅에서 경고가 나므로,
        // 문자열이 아닌 값은 곧장 빈 문자열로 취급해 published 로 떨어뜨린다.
        $value = is_string($value) ? $value : '';

        return in_array($value, Post::STATUSES, true) ? $value : Post::STATUS_PUBLISHED;
    }

    /**
     * 업로드된 대표 이미지를 검증·저장하고 저장 파일명을 돌려준다.
     *
     * @return string|false|null 저장 파일명 / 검증 실패(false) / 업로드 없음(null)
     */
    private function saveUploadedImage(): string|false|null
    {
        $file = $this->request->getFile('image');

        // 파일을 고르지 않았으면 이미지 없이 진행한다.
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        // 이미지 형식·용량 검증(2MB 이하).
        if (! $this->validate([
            'image' => 'is_image[image]|mime_in[image,image/jpg,image/jpeg,image/png,image/webp]|max_size[image,2048]',
        ])) {
            return false;
        }

        $dir  = WRITEPATH . 'uploads';
        $name = $file->getRandomName();
        $file->move($dir, $name);

        // 목록용 썸네일(400x250 크롭). 원본은 상세에서 사용.
        service('image')
            ->withFile($dir . '/' . $name)
            ->fit(400, 250, 'center')
            ->save($dir . '/thumb_' . $name);

        return $name;
    }

    /**
     * 글에 딸린 이미지 원본과 썸네일을 파일시스템에서 지운다.
     */
    private function deleteImageFiles(?string $name): void
    {
        if ($name === null || $name === '') {
            return;
        }

        foreach ([$name, 'thumb_' . $name] as $f) {
            $path = WRITEPATH . 'uploads/' . $f;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * writable/uploads 의 이미지를 스트리밍한다(웹 루트 밖이라 컨트롤러로 서빙).
     */
    public function image(string $name): ResponseInterface
    {
        $name = basename($name); // 경로 탈출 방지
        $path = WRITEPATH . 'uploads/' . $name;

        if (! is_file($path)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response
            ->setHeader('Content-Type', mime_content_type($path) ?: 'application/octet-stream')
            ->setBody((string) file_get_contents($path));
    }

    /**
     * 글을 삭제한다. 작성자 본인 또는 관리자만 가능하다.
     */
    public function delete(int $id): ResponseInterface|RedirectResponse
    {
        $model = model(PostModel::class);
        $post  = $model->find($id);

        if ($post === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        // 컨트롤러 가드: 권한이 없으면 403 으로 막는다.
        if (! $this->canModify($post)) {
            return $this->response->setStatusCode(403, '삭제 권한이 없습니다.');
        }

        $model->delete($id);

        return redirect()->to('posts')->with('message', '글이 삭제되었습니다.');
    }

    /**
     * 좋아요 토글(#64). 세션 필터 그룹 안이라 로그인 사용자만 들어온다.
     */
    public function like(int $id): RedirectResponse
    {
        $post = model(PostModel::class)->find($id);

        if ($post === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        // 상세(show)와 같은 가드를 그대로 쓴다 — 상세가 404 인 글에 좋아요만 열려 있으면
        // 응답 차이로 글의 존재가 샌다.
        $this->assertViewable($post);

        $likes  = model(PostLikeModel::class);
        $userId = (int) auth()->id();

        // 검사-후-삽입(hasLiked 로 분기)은 동시 요청 두 개가 모두 통과하는 레이스가 있다.
        // 그래서 #88 처럼 그 구조를 아예 두지 않는다 — 먼저 넣어 보고, 유니크 키
        // (post_id, user_id) 위반으로 실패하면 그게 곧 "이미 눌렀다"는 뜻이라 취소로 간다.
        // 위반은 DBDebug=true 면 예외로, false 면 insert=false 로 온다.
        $dbException = null;

        try {
            $inserted = $likes->insert(['post_id' => $id, 'user_id' => $userId]);
        } catch (DatabaseException $e) {
            $inserted    = false;
            $dbException = $e;
        }

        if (! $inserted) {
            // 이미 좋아요가 있으면 취소한다(토글). 지울 게 없으면 0 행이 지워질 뿐이라
            // 삭제 쪽 레이스는 무해하다 — 최종 상태가 "좋아요 없음"으로 같다.
            if ($likes->hasLiked($id, $userId)) {
                $likes->where('post_id', $id)->where('user_id', $userId)->delete();
            } else {
                // 중복이 아닌 DB 오류는 숨기지 않고 그대로 전파한다.
                if ($dbException !== null) {
                    throw $dbException;
                }

                return redirect()->back()->with('errors', $likes->errors());
            }
        }

        return redirect()->to('posts/' . $post->slug . '#like');
    }

    /**
     * 이 글을 지금 사용자에게 보여 줘도 되는지 확인하고, 아니면 404 를 던진다.
     *
     * 상세(show)와 좋아요(like)가 함께 쓴다. 두 곳에 복붙해 두면 가드가 또 늘 때
     * 한쪽만 고치는 사고가 난다 — 댓글 신고(#79)가 정확히 그 사고였다.
     *
     * 403 이 아니라 404 를 주는 건 의도적이다 — 403 은 그 슬러그의 글이
     * 존재한다는 사실 자체를 흘린다.
     */
    private function assertViewable(Post $post): void
    {
        // 비발행 글(초안·비공개)은 작성자 본인과 관리자에게만 미리보기로 열어 준다.
        if (! $post->isPublished() && ! $this->canModify($post)) {
            throw PageNotFoundException::forPageNotFound();
        }

        // 숨김 카테고리(#67)에 속한 글도 같은 규칙으로 가린다. 카테고리를 숨긴다는 건
        // 그 글들을 공개 화면에서 뺀다는 뜻이므로, 목록에서만 빼고 상세는 열어 두면
        // 슬러그를 아는 사람에게 그대로 노출된다.
        if ($post->category_id !== null && ! $this->canModify($post)) {
            $postCategory = model(CategoryModel::class)->find($post->category_id);
            if ($postCategory !== null && ! $postCategory->is_visible) {
                throw PageNotFoundException::forPageNotFound();
            }
        }
    }

    /**
     * 현재 사용자가 이 글을 수정/삭제할 수 있는지 판단한다.
     * 공통 규칙(작성자 본인 또는 admin)은 acl 헬퍼로 모았다.
     */
    private function canModify(Post $post): bool
    {
        return is_owner_or_admin($post->user_id);
    }
}
