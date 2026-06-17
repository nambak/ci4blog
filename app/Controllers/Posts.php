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

        return view('posts/show', [
            'post'     => $post,
            'comments' => $comments,
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

        // slug 는 PostModel 의 beforeInsert 콜백이 제목으로 자동 생성한다.

        // 검증 실패 시: 입력값을 그대로 들고 폼으로 되돌아간다.
        if (! $model->insert($data)) {
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
    public function edit(int $id): string
    {
        $post = model(PostModel::class)->find($id);

        if ($post === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('posts/edit', [
            'post' => $post,
        ]);
    }

    /**
     * 수정된 값을 검증하고 저장한다.
     */
    public function update(int $id): RedirectResponse
    {
        $model = model(PostModel::class);
        $post  = $model->find($id);

        if ($post === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $data = $this->request->getPost(['title', 'body']);

        // 검증 실패 시: 입력값을 들고 수정 폼으로 되돌아간다.
        if (! $model->update($id, $data)) {
            return redirect()->back()
                ->withInput()
                ->with('errors', $model->errors());
        }

        // 수정 성공 시: 해당 글 상세로 이동하며 플래시 메시지를 남긴다.
        return redirect()->to('posts/' . $post->slug)->with('message', '글이 수정되었습니다.');
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
     * 작성자 본인이거나 admin 그룹이면 true.
     */
    private function canModify(Post $post): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return (int) $post->user_id === (int) $user->id
            || $user->inGroup('admin');
    }
}
