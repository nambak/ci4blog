<?php

namespace App\Controllers;

use App\Models\PostModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

class Posts extends BaseController
{
    // 한 페이지에 보여 줄 글 수
    private const PER_PAGE = 5;

    public function index(): string
    {
        $model = model(PostModel::class);

        $posts = $model
            ->orderBy('created_at', 'DESC')
            ->paginate(self::PER_PAGE);

        return view('posts/index', [
            'posts' => $posts,
            'pager' => $model->pager,
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

        return view('posts/show', [
            'post' => $post,
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

        // 임시 slug. posts.slug 가 NOT NULL UNIQUE 라 지금은 값을 채워 둔다.
        // ep17 에서 제목 기반 생성 + 중복 처리를 PostModel::beforeInsert 로 옮긴다.
        $data['slug'] = 'post-' . bin2hex(random_bytes(6));

        // 검증 실패 시: 입력값을 그대로 들고 폼으로 되돌아간다.
        if (! $model->insert($data)) {
            return redirect()->back()
                ->withInput()
                ->with('errors', $model->errors());
        }

        // 저장 성공 시: 목록으로 이동한다.
        return redirect()->to('posts');
    }
}
