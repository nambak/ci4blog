<?php

namespace App\Controllers;

use App\Models\PostModel;
use CodeIgniter\Exceptions\PageNotFoundException;

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
}
