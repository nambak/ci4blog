<?php

namespace App\Controllers;

use App\Entities\Post;
use App\Models\CategoryModel;
use App\Models\CommentModel;
use App\Models\PostModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

class Posts extends BaseController
{
    // 한 페이지에 보여 줄 글 수
    private const PER_PAGE = 5;

    /**
     * 글 목록. 카테고리 슬러그가 주어지면 그 카테고리 글만 거르고,
     * 검색어(q)가 주어지면 제목·본문을 like 로 찾는다.
     *
     * `posts` → 전체, `categories/{slug}` → 해당 카테고리, `?q=...` → 검색.
     */
    public function index(?string $categorySlug = null): string
    {
        $model = model(PostModel::class);

        // 없는 카테고리는 404. (필터가 빈 목록으로 조용히 떨어지지 않게)
        $activeCategory = null;
        if ($categorySlug !== null) {
            $activeCategory = model(CategoryModel::class)->where('slug', $categorySlug)->first();
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

        // 이 글의 댓글을 작성자명과 함께 한 번에 로드한다(N+1 회피).
        $comments = model(CommentModel::class)->forPost((int) $post->id);

        // 바이라인(작성자 아바타 행)용 작성자명. 홈 히어로와 같은 방식으로
        // users 테이블에서 username 만 직접 읽는다(엔티티 의존 없이).
        $authorName = null;
        if ($post->user_id !== null) {
            $row        = db_connect()->table('users')->select('username')->where('id', $post->user_id)->get()->getRow();
            $authorName = $row->username ?? null;
        }

        // 제목 위 카테고리 칩. 없는 글(미분류)은 null.
        $category = $post->category_id !== null
            ? model(CategoryModel::class)->find($post->category_id)
            : null;

        return view('posts/show', [
            'post'       => $post,
            'comments'   => $comments,
            'authorName' => $authorName,
            'category'   => $category,
        ]);
    }

    /**
     * 글 작성 폼을 보여 준다. (세션 필터로 로그인 사용자만 접근)
     *
     * 폼 뷰(posts/create)는 ep13 에서 추가한다.
     */
    public function new(): string
    {
        return view('posts/create');
    }

    /**
     * 폼에서 넘어온 글을 검증하고 저장한다.
     */
    public function create(): RedirectResponse
    {
        $model = model(PostModel::class);

        // allowedFields 에 든 값만 추려서 받는다.
        $data = $this->request->getPost(['title', 'body']);

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

        $data = $this->request->getPost(['title', 'body']);

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
     * 현재 사용자가 이 글을 수정/삭제할 수 있는지 판단한다.
     * 공통 규칙(작성자 본인 또는 admin)은 acl 헬퍼로 모았다.
     */
    private function canModify(Post $post): bool
    {
        return is_owner_or_admin($post->user_id);
    }
}
